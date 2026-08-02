<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Models\CustodyAccount;
use App\Models\CustodyJournalSourceLink;
use App\Models\MerchantSettlementEntry;
use App\Services\Settlement\SettlementDecimal;
use App\Support\Assets\AssetRegistry;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class Phase2AVerifier
{
    private const BASELINE_TABLES = [
        'custody_account_balances',
        'custody_accounts',
        'custody_journal_entries',
        'custody_journal_postings',
        'custody_journal_source_links',
        'custody_phase2a_cutovers',
        'merchant_balances',
        'merchant_settlement_entries',
    ];

    public function __construct(
        private Phase2ASourceSnapshot $snapshots,
        private CustodyProjectionVerifier $projectionVerifier,
        private SettlementDecimal $decimal,
        private AssetRegistry $assets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        if (DB::transactionLevel() > 0) {
            if (! app()->environment('testing')) {
                throw new CustodyAccountingException(
                    'Phase 2A verifier requires its own REPEATABLE READ, READ ONLY transaction.',
                );
            }

            return $this->verifySnapshot();
        }

        return DB::transaction(function (): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->verifySnapshot();
        });
    }

    /**
     * This method performs reads only. Cutover activation calls it after taking
     * the exclusive phase lock in its READ COMMITTED transaction.
     *
     * @return array<string, mixed>
     */
    public function verifySnapshot(): array
    {
        $counts = [];
        foreach (self::BASELINE_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        $projection = $this->projectionVerifier->verify();
        $sourceRows = [];
        $scopeState = [];
        $offsetState = [];
        $coveredCount = 0;
        $uncoveredCount = 0;
        $snapshotMutationCount = 0;
        $linkJournalCorruptCount = 0;

        $sources = MerchantSettlementEntry::query()
            ->where('type', MerchantSettlementEntry::TYPE_INTERNAL_CREDIT)
            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->with([
                'invoice',
                'custodyJournalSourceLink.journalEntry.postings.account',
            ])
            ->orderBy('id')
            ->get();

        foreach ($sources as $source) {
            $link = $source->custodyJournalSourceLink;
            $snapshotMutated = false;
            $journalCorrupt = false;
            $expected = null;

            try {
                $expected = $this->snapshots->build($source);
            } catch (Throwable) {
                $snapshotMutated = true;
            }

            if ($link === null) {
                $uncoveredCount++;
            } else {
                $coveredCount++;
                if ($expected === null || ! $this->snapshotLinkMatches($link, $expected)) {
                    $snapshotMutated = true;
                }

                if ($expected === null || ! $this->journalMatches($source, $link, $expected)) {
                    $journalCorrupt = true;
                }
            }

            if ($snapshotMutated) {
                $snapshotMutationCount++;
            }
            if ($journalCorrupt) {
                $linkJournalCorruptCount++;
            }

            $validCovered = $link !== null && ! $snapshotMutated && ! $journalCorrupt;
            $amount = BigDecimal::of((string) $source->getRawOriginal('amount_coin'));
            $scopeKey = $this->scopeKey(
                (int) $source->merchant_id,
                $source->asset_key,
                (string) $source->network_key,
            );
            $scopeState[$scopeKey] ??= $this->newScopeState(
                (int) $source->merchant_id,
                $source->asset_key,
                (string) $source->network_key,
            );
            $scopeState[$scopeKey]['all_total'] = $scopeState[$scopeKey]['all_total']->plus($amount);
            $scopeState[$scopeKey]['completed_count']++;
            if ($link === null) {
                $scopeState[$scopeKey]['uncovered_total'] = $scopeState[$scopeKey]['uncovered_total']->plus($amount);
            }
            if ($validCovered) {
                $scopeState[$scopeKey]['valid_covered_total'] = $scopeState[$scopeKey]['valid_covered_total']
                    ->plus($amount);
                $scopeState[$scopeKey]['valid_covered_count']++;
                $offsetKey = $this->offsetKey($source->asset_key, (string) $source->network_key);
                $offsetState[$offsetKey] ??= [
                    'asset_key' => $source->asset_key,
                    'network_key' => (string) $source->network_key,
                    'valid_covered_total' => BigDecimal::zero(),
                ];
                $offsetState[$offsetKey]['valid_covered_total'] = $offsetState[$offsetKey]['valid_covered_total']
                    ->plus($amount);
            }

            $sourceRows[] = [
                'source_id' => (int) $source->id,
                'merchant_id' => (int) $source->merchant_id,
                'invoice_id' => (int) $source->invoice_id,
                'asset_key' => $source->asset_key,
                'network_key' => $source->network_key,
                'covered' => $link !== null,
                'source_snapshot_mutated' => $snapshotMutated,
                'link_or_journal_corrupt' => $journalCorrupt,
                'valid_covered' => $validCovered,
            ];
        }

        $balances = DB::table('merchant_balances')->orderBy('merchant_id')->orderBy('coin')->get();
        foreach ($balances as $balance) {
            $assetKey = (string) $balance->coin;
            $networkKey = $this->networkOrUnknown($assetKey);
            $scopeKey = $this->scopeKey((int) $balance->merchant_id, $assetKey, $networkKey);
            $scopeState[$scopeKey] ??= $this->newScopeState(
                (int) $balance->merchant_id,
                $assetKey,
                $networkKey,
            );
            $scopeState[$scopeKey]['merchant_balance'] = (string) $balance->amount;
        }

        $merchantAvailableAccounts = CustodyAccount::query()
            ->where('account_code', CustodyAccount::CODE_MERCHANT_AVAILABLE)
            ->whereNotNull('merchant_id')
            ->orderBy('merchant_id')
            ->orderBy('asset_key')
            ->orderBy('network_key')
            ->get();
        foreach ($merchantAvailableAccounts as $account) {
            $scopeKey = $this->scopeKey(
                (int) $account->merchant_id,
                $account->asset_key,
                $account->network_key,
            );
            $scopeState[$scopeKey] ??= $this->newScopeState(
                (int) $account->merchant_id,
                $account->asset_key,
                $account->network_key,
            );
        }

        $offsetAccounts = CustodyAccount::query()
            ->where('account_code', CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET)
            ->orderBy('asset_key')
            ->orderBy('network_key')
            ->get();
        foreach ($offsetAccounts as $account) {
            $offsetKey = $this->offsetKey($account->asset_key, $account->network_key);
            $offsetState[$offsetKey] ??= [
                'asset_key' => $account->asset_key,
                'network_key' => $account->network_key,
                'valid_covered_total' => BigDecimal::zero(),
            ];
        }

        ksort($scopeState, SORT_STRING);
        $scopeRows = [];
        $residualCount = 0;
        $scopeMismatchCount = 0;
        foreach ($scopeState as $state) {
            $merchantBalance = BigDecimal::of($state['merchant_balance']);
            $allTotal = $state['all_total'];
            $validCoveredTotal = $state['valid_covered_total'];
            $merchantProjection = $this->projectionBalance(
                merchantId: $state['merchant_id'],
                assetKey: $state['asset_key'],
                networkKey: $state['network_key'],
                accountCode: CustodyAccount::CODE_MERCHANT_AVAILABLE,
                scopeKey: "merchant:{$state['merchant_id']}",
            );
            $residual = $merchantBalance->minus($allTotal);
            $balanceMatchesAll = $merchantBalance->compareTo($allTotal) === 0;
            $journalMatchesValid = BigDecimal::of($merchantProjection)->compareTo($validCoveredTotal) === 0;
            $scopeMismatch = ! $balanceMatchesAll || ! $journalMatchesValid;

            if (! $residual->isZero()) {
                $residualCount++;
            }
            if ($scopeMismatch) {
                $scopeMismatchCount++;
            }

            $scopeRows[] = [
                'merchant_id' => $state['merchant_id'],
                'asset_key' => $state['asset_key'],
                'network_key' => $state['network_key'],
                'merchant_balance' => $this->storage($merchantBalance),
                'completed_internal_credit_total' => $this->storage($allTotal),
                'valid_covered_source_total' => $this->storage($validCoveredTotal),
                'uncovered_source_credit_total' => $this->storage($state['uncovered_total']),
                'merchant_available_projection' => $merchantProjection,
                'unexplained_legacy_residual' => $this->storage($residual),
                'completed_internal_credit_count' => $state['completed_count'],
                'valid_covered_count' => $state['valid_covered_count'],
                'balance_matches_completed_total' => $balanceMatchesAll,
                'journal_matches_valid_covered_total' => $journalMatchesValid,
            ];
        }

        ksort($offsetState, SORT_STRING);
        $offsetRows = [];
        $offsetMismatchCount = 0;
        foreach ($offsetState as $state) {
            $projectionBalance = $this->projectionBalance(
                merchantId: null,
                assetKey: $state['asset_key'],
                networkKey: $state['network_key'],
                accountCode: CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET,
                scopeKey: 'platform',
            );
            $matches = BigDecimal::of($projectionBalance)
                ->compareTo($state['valid_covered_total']) === 0;
            if (! $matches) {
                $offsetMismatchCount++;
            }
            $offsetRows[] = [
                'asset_key' => $state['asset_key'],
                'network_key' => $state['network_key'],
                'offset_projection' => $projectionBalance,
                'valid_linked_liability_total' => $this->storage($state['valid_covered_total']),
                'matches' => $matches,
            ];
        }

        $parity = [
            'completed_internal_credit_count' => $sources->count(),
            'covered_internal_credit_count' => $coveredCount,
            'projection_drift_count' => (int) $projection['drift_count'],
            'source_snapshot_mutation_count' => $snapshotMutationCount,
            'uncovered_internal_credit_count' => $uncoveredCount,
            'unexplained_legacy_residual_count' => $residualCount,
        ];
        $issueCount = $uncoveredCount
            + $snapshotMutationCount
            + $linkJournalCorruptCount
            + $scopeMismatchCount
            + $offsetMismatchCount
            + (int) $projection['drift_count'];

        return [
            'clean' => $issueCount === 0,
            'issue_count' => $issueCount,
            'counts' => $counts,
            'parity' => $parity,
            'link_journal_corrupt_count' => $linkJournalCorruptCount,
            'scope_mismatch_count' => $scopeMismatchCount,
            'offset_mismatch_count' => $offsetMismatchCount,
            'source_rows' => $sourceRows,
            'merchant_scope_rows' => $scopeRows,
            'offset_scope_rows' => $offsetRows,
            'projection' => $projection,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function zeroBaselinePayload(array $report): array
    {
        return [
            'baseline_schema_version' => 'custody_phase2a_zero_parity_baseline_v1',
            'counts' => array_intersect_key($report['counts'], array_flip(self::BASELINE_TABLES)),
            'parity' => $report['parity'],
        ];
    }

    private function snapshotLinkMatches(
        CustodyJournalSourceLink $link,
        object $expected,
    ): bool {
        return $link->asset_scale === $expected->assetScale
            && $link->source_kind === CustodyJournalSourceLink::SOURCE_KIND
            && $link->source_version === CustodyJournalSourceLink::SOURCE_VERSION
            && $link->source_snapshot_canonical_text === $expected->canonicalText
            && hash_equals($link->source_snapshot_hash, $expected->hash)
            && ($link->source_snapshot_jsonb === null
                || $link->source_snapshot_jsonb == $expected->payload);
    }

    private function journalMatches(
        MerchantSettlementEntry $source,
        CustodyJournalSourceLink $link,
        object $expected,
    ): bool {
        $journal = $link->journalEntry;
        if ($journal === null || $journal->postings->count() !== 2) {
            return false;
        }

        $metadata = [
            'asset_scale' => $expected->assetScale,
            'merchant_settlement_entry_id' => (int) $source->id,
            'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
            'source_snapshot_hash' => $expected->hash,
            'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
        ];
        $effectiveAt = $journal->effective_at === null
            ? null
            : CarbonImmutable::instance($journal->effective_at)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $sourceOccurredAt = CarbonImmutable::instance($source->occurred_at)->utc()->format('Y-m-d\TH:i:s.u\Z');

        if (
            $journal->event_type !== Phase2AInternalCreditProjector::EVENT_TYPE
            || $journal->posted_at === null
            || $journal->reversal_of_id !== null
            || (int) $journal->merchant_id !== (int) $source->merchant_id
            || $journal->idempotency_key
                !== "custody:internal-credit:merchant-settlement-entry:{$source->id}:v1"
            || $journal->source_reference !== "merchant_settlement_entry:{$source->id}"
            || $effectiveAt !== $sourceOccurredAt
            || $journal->asset_key !== $source->asset_key
            || $journal->network_key !== $source->network_key
            || $journal->asset_scale !== $expected->assetScale
            || $journal->reason !== 'internal_balance_only'
            || $journal->immutable_metadata != $metadata
            || DB::table('custody_journal_entries')->where('reversal_of_id', $journal->id)->exists()
        ) {
            return false;
        }

        $debits = 0;
        $credits = 0;
        $accountIds = [];
        foreach ($journal->postings as $posting) {
            $account = $posting->account;
            if (
                $account === null
                || $this->decimal->formatExact((string) $posting->amount, $source->asset_key)
                    !== $expected->amount
                || $posting->amount_atomic !== $expected->amountAtomic
                || $account->asset_key !== $source->asset_key
                || $account->network_key !== $source->network_key
                || $account->asset_scale !== $expected->assetScale
            ) {
                return false;
            }

            $accountIds[] = (int) $account->id;
            if (
                $posting->side === CustodyAccount::SIDE_DEBIT
                && $account->account_code === CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET
                && $account->scope_key === 'platform'
                && $account->merchant_id === null
                && $account->normal_side === CustodyAccount::SIDE_DEBIT
            ) {
                $debits++;
            }
            if (
                $posting->side === CustodyAccount::SIDE_CREDIT
                && $account->account_code === CustodyAccount::CODE_MERCHANT_AVAILABLE
                && $account->scope_key === "merchant:{$source->merchant_id}"
                && (int) $account->merchant_id === (int) $source->merchant_id
                && $account->normal_side === CustodyAccount::SIDE_CREDIT
            ) {
                $credits++;
            }
        }

        return $debits === 1 && $credits === 1 && count(array_unique($accountIds)) === 2;
    }

    /**
     * @return array<string, mixed>
     */
    private function newScopeState(int $merchantId, string $assetKey, string $networkKey): array
    {
        return [
            'merchant_id' => $merchantId,
            'asset_key' => $assetKey,
            'network_key' => $networkKey,
            'merchant_balance' => '0',
            'all_total' => BigDecimal::zero(),
            'valid_covered_total' => BigDecimal::zero(),
            'uncovered_total' => BigDecimal::zero(),
            'completed_count' => 0,
            'valid_covered_count' => 0,
        ];
    }

    private function projectionBalance(
        ?int $merchantId,
        string $assetKey,
        string $networkKey,
        string $accountCode,
        string $scopeKey,
    ): string {
        $accountId = DB::table('custody_accounts')
            ->where('scope_key', $scopeKey)
            ->where('asset_key', $assetKey)
            ->where('network_key', $networkKey)
            ->where('account_code', $accountCode)
            ->when($merchantId === null, fn ($query) => $query->whereNull('merchant_id'))
            ->when($merchantId !== null, fn ($query) => $query->where('merchant_id', $merchantId))
            ->value('id');

        if ($accountId === null) {
            return $this->storage(BigDecimal::zero());
        }

        return (string) (DB::table('custody_account_balances')
            ->where('account_id', $accountId)
            ->value('balance') ?? $this->storage(BigDecimal::zero()));
    }

    private function storage(BigDecimal $value): string
    {
        return (string) $value->toScale(18);
    }

    private function networkOrUnknown(string $assetKey): string
    {
        try {
            return $this->assets->network($assetKey);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function scopeKey(int $merchantId, string $assetKey, string $networkKey): string
    {
        return "{$merchantId}:{$assetKey}:{$networkKey}";
    }

    private function offsetKey(string $assetKey, string $networkKey): string
    {
        return "{$assetKey}:{$networkKey}";
    }
}
