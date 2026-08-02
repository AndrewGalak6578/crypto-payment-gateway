<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Data\CustodyPostingData;
use App\Data\Phase2ASourceSnapshotData;
use App\Exceptions\CustodyAccountingException;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\CustodyAccount;
use App\Models\CustodyJournalEntry;
use App\Models\CustodyJournalSourceLink;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementEntry;
use Illuminate\Support\Facades\DB;

final readonly class Phase2AInternalCreditProjector
{
    public const EVENT_TYPE = 'internal_credit_shadow_v1';

    public function __construct(
        private CustodyAccountRepository $accounts,
        private CustodyJournalWriter $writer,
        private Phase2ASourceSnapshot $snapshots,
        private Phase2AParityGuard $parityGuard,
    ) {}

    public function projectCreatedSource(
        MerchantSettlementEntry $source,
        MerchantBalance $lockedBalance,
    ): CustodyJournalSourceLink {
        if (DB::transactionLevel() < 1) {
            throw new CustodyAccountingException('Phase 2A projection requires an outer transaction.');
        }

        $snapshot = $this->snapshots->build($source);
        if (CustodyJournalSourceLink::query()
            ->where('merchant_settlement_entry_id', $source->id)
            ->exists()) {
            throw new CustodyIdempotencyConflictException(
                "Phase 2A source [{$source->id}] already has a journal link.",
            );
        }

        $offset = $this->accounts->platform(
            $source->asset_key,
            (string) $source->network_key,
            CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET,
        );
        $available = $this->accounts->merchant(
            (int) $source->merchant_id,
            $source->asset_key,
            (string) $source->network_key,
            CustodyAccount::CODE_MERCHANT_AVAILABLE,
        );
        $transaction = $this->journalTransaction($source, $snapshot, $offset->id, $available->id);

        if (CustodyJournalEntry::query()
            ->where('idempotency_key', $transaction->idempotencyKey)
            ->exists()) {
            throw new CustodyIdempotencyConflictException(
                "New Phase 2A source [{$source->id}] unexpectedly has a pre-existing journal.",
            );
        }

        $journal = $this->writer->postGuarded(
            $transaction,
            function (
                int $entryId,
                $lockedAccounts,
                $lockedProjections,
                array $postings,
            ) use ($source, $lockedBalance, $offset, $available): void {
                if (
                    $entryId <= 0
                    || $lockedAccounts->keys()->sort()->values()->all()
                        !== collect([$offset->id, $available->id])->sort()->values()->all()
                    || count($postings) !== 2
                ) {
                    throw new CustodyAccountingException('Guarded journal lock state is not exact.');
                }

                $this->parityGuard->assertBeforeProjectionDelta(
                    source: $source,
                    lockedBalance: $lockedBalance,
                    offsetAccountId: $offset->id,
                    merchantAvailableAccountId: $available->id,
                    lockedProjections: $lockedProjections,
                );
            },
        );

        DB::table('custody_journal_source_links')->insert([
            'merchant_settlement_entry_id' => $source->id,
            'custody_journal_entry_id' => $journal->id,
            'asset_scale' => $snapshot->assetScale,
            'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
            'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
            'source_snapshot_canonical_text' => $snapshot->canonicalText,
            'source_snapshot_hash' => $snapshot->hash,
            'source_snapshot_jsonb' => $snapshot->canonicalText,
            'created_at' => now('UTC'),
        ]);

        /** @var CustodyJournalSourceLink $link */
        $link = CustodyJournalSourceLink::query()
            ->where('merchant_settlement_entry_id', $source->id)
            ->firstOrFail();
        $this->validatePersistedLink($link, $snapshot, $journal);

        return $link;
    }

    public function replayExact(int $sourceId): CustodyJournalEntry
    {
        return DB::transaction(function () use ($sourceId): CustodyJournalEntry {
            /** @var MerchantSettlementEntry $source */
            $source = MerchantSettlementEntry::query()
                ->with('invoice')
                ->lockForUpdate()
                ->findOrFail($sourceId);
            $snapshot = $this->snapshots->build($source);

            /** @var CustodyJournalSourceLink|null $link */
            $link = CustodyJournalSourceLink::query()
                ->where('merchant_settlement_entry_id', $source->id)
                ->first();
            if ($link === null) {
                throw new CustodyIdempotencyConflictException(
                    "Phase 2A source [{$source->id}] has no immutable source link.",
                );
            }

            $offset = CustodyAccount::query()
                ->where('scope_key', 'platform')
                ->where('asset_key', $source->asset_key)
                ->where('network_key', $source->network_key)
                ->where('account_code', CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET)
                ->first();
            $available = CustodyAccount::query()
                ->where('scope_key', "merchant:{$source->merchant_id}")
                ->where('asset_key', $source->asset_key)
                ->where('network_key', $source->network_key)
                ->where('account_code', CustodyAccount::CODE_MERCHANT_AVAILABLE)
                ->first();

            if ($offset === null || $available === null) {
                throw new CustodyIdempotencyConflictException('Phase 2A replay accounts are missing.');
            }

            $journal = $this->writer->post(
                $this->journalTransaction($source, $snapshot, $offset->id, $available->id),
            );
            $this->validatePersistedLink($link->fresh(), $snapshot, $journal);

            DB::select('SELECT custody_phase2a_validate_link(CAST(? AS bigint))', [$link->id]);

            return $journal;
        }, 5);
    }

    private function journalTransaction(
        MerchantSettlementEntry $source,
        Phase2ASourceSnapshotData $snapshot,
        int $offsetAccountId,
        int $availableAccountId,
    ): CustodyJournalTransactionData {
        return new CustodyJournalTransactionData(
            idempotencyKey: "custody:internal-credit:merchant-settlement-entry:{$source->id}:v1",
            eventType: self::EVENT_TYPE,
            assetKey: $source->asset_key,
            networkKey: (string) $source->network_key,
            merchantId: (int) $source->merchant_id,
            sourceReference: "merchant_settlement_entry:{$source->id}",
            effectiveAt: $source->occurred_at,
            reason: 'internal_balance_only',
            immutableMetadata: [
                'asset_scale' => $snapshot->assetScale,
                'merchant_settlement_entry_id' => (int) $source->id,
                'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
                'source_snapshot_hash' => $snapshot->hash,
                'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
            ],
            postings: [
                new CustodyPostingData(
                    $offsetAccountId,
                    CustodyAccount::SIDE_DEBIT,
                    $snapshot->amount,
                    $snapshot->amountAtomic,
                ),
                new CustodyPostingData(
                    $availableAccountId,
                    CustodyAccount::SIDE_CREDIT,
                    $snapshot->amount,
                    $snapshot->amountAtomic,
                ),
            ],
        );
    }

    private function validatePersistedLink(
        ?CustodyJournalSourceLink $link,
        Phase2ASourceSnapshotData $snapshot,
        CustodyJournalEntry $journal,
    ): void {
        if (
            $link === null
            || (int) $link->custody_journal_entry_id !== (int) $journal->id
            || $link->asset_scale !== $snapshot->assetScale
            || $link->source_kind !== CustodyJournalSourceLink::SOURCE_KIND
            || $link->source_version !== CustodyJournalSourceLink::SOURCE_VERSION
            || $link->source_snapshot_canonical_text !== $snapshot->canonicalText
            || ! hash_equals($link->source_snapshot_hash, $snapshot->hash)
            || ($link->source_snapshot_jsonb !== null
                && $link->source_snapshot_jsonb != $snapshot->payload)
        ) {
            throw new CustodyIdempotencyConflictException('Phase 2A source link is not an exact replay.');
        }
    }
}
