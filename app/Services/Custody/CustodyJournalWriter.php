<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Data\CustodyPostingData;
use App\Exceptions\CustodyAccountingException;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\CustodyAccount;
use App\Models\CustodyJournalEntry;
use App\Support\Assets\AssetRegistry;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CustodyJournalWriter
{
    public function __construct(
        private AssetRegistry $assets,
        private CustodyDecimal $decimal,
        private CustodyCanonicalPayload $canonicalPayload,
    ) {}

    public function post(CustodyJournalTransactionData $transaction): CustodyJournalEntry
    {
        return $this->write($transaction, null);
    }

    /**
     * @param  Closure(int, Collection<int, CustodyAccount>, Collection<int, object>, list<array{account_id: int, side: string, amount: string, amount_atomic: ?string}>): void  $beforeProjectionDelta
     */
    public function postGuarded(
        CustodyJournalTransactionData $transaction,
        Closure $beforeProjectionDelta,
    ): CustodyJournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new CustodyAccountingException(
                'Guarded custody journal writes require an existing outer database transaction.',
            );
        }

        return $this->write($transaction, $beforeProjectionDelta);
    }

    /**
     * @param  (Closure(int, Collection<int, CustodyAccount>, Collection<int, object>, list<array{account_id: int, side: string, amount: string, amount_atomic: ?string}>): void)|null  $beforeProjectionDelta
     */
    private function write(
        CustodyJournalTransactionData $transaction,
        ?Closure $beforeProjectionDelta,
    ): CustodyJournalEntry {
        $this->assertWritesEnabled();
        $assetKey = strtolower(trim($transaction->assetKey));
        $networkKey = trim($transaction->networkKey);
        $asset = $this->assets->get($assetKey);
        $assetScale = $this->assets->settlementScale($assetKey);

        if ((string) $asset['network'] !== $networkKey) {
            throw new CustodyAccountingException('Journal network does not match the asset registry.');
        }

        $this->validateReference($transaction->idempotencyKey, 'Idempotency key', 191);
        $this->validateReference($transaction->eventType, 'Event type', 64);
        $this->validateOptionalReference($transaction->sourceReference, 'Source reference', 191);
        $this->validateOptionalReference($transaction->reason, 'Reason', 96);

        if ($transaction->merchantId !== null && $transaction->merchantId <= 0) {
            throw new CustodyAccountingException('Merchant reference must be a positive integer.');
        }

        if (count($transaction->postings) < 2) {
            throw new CustodyAccountingException('A journal entry requires at least two postings.');
        }

        $entryId = DB::transaction(function () use (
            $transaction,
            $assetKey,
            $networkKey,
            $assetScale,
            $beforeProjectionDelta,
        ): int {
            $accountIds = collect($transaction->postings)
                ->map(fn (CustodyPostingData $posting): int => $posting->accountId)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (count($accountIds) < 2) {
                throw new CustodyAccountingException(
                    'A journal entry requires postings across at least two distinct custody accounts.',
                );
            }

            if (min($accountIds) <= 0) {
                throw new CustodyAccountingException('Journal postings require valid custody account IDs.');
            }

            /** @var Collection<int, CustodyAccount> $accounts */
            $accounts = CustodyAccount::query()
                ->whereKey($accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($accounts->count() !== count($accountIds)) {
                throw new CustodyAccountingException('A journal posting references an unknown custody account.');
            }

            foreach ($accounts as $account) {
                if (
                    $account->asset_key !== $assetKey
                    || $account->network_key !== $networkKey
                    || $account->asset_scale !== $assetScale
                ) {
                    throw new CustodyAccountingException(
                        'Every journal account must use the same asset, network, and immutable scale.',
                    );
                }

                if (
                    $account->merchant_id !== null
                    && $account->merchant_id !== $transaction->merchantId
                ) {
                    throw new CustodyAccountingException(
                        'Journal merchant does not own every referenced liability account.',
                    );
                }
            }

            $normalizedPostings = $this->normalizePostings($transaction->postings, $assetScale);
            $this->assertBalanced($normalizedPostings);
            $effectiveAt = $this->canonicalTime($transaction->effectiveAt);

            $canonical = [
                'event_type' => $transaction->eventType,
                'merchant_id' => $transaction->merchantId,
                'source_reference' => $transaction->sourceReference,
                'asset_key' => $assetKey,
                'network_key' => $networkKey,
                'asset_scale' => $assetScale,
                'effective_at' => $effectiveAt,
                'reason' => $transaction->reason,
                'reversal_of_id' => $transaction->reversalOfId,
                'postings' => $normalizedPostings,
                'immutable_metadata' => $transaction->immutableMetadata,
            ];
            $payloadHash = $this->canonicalPayload->hash($canonical);
            $createdAt = now('UTC');
            $inserted = DB::table('custody_journal_entries')->insertOrIgnore([
                'entry_uuid' => (string) Str::uuid(),
                'idempotency_key' => $transaction->idempotencyKey,
                'canonical_payload_hash' => $payloadHash,
                'event_type' => $transaction->eventType,
                'merchant_id' => $transaction->merchantId,
                'source_reference' => $transaction->sourceReference,
                'asset_key' => $assetKey,
                'network_key' => $networkKey,
                'asset_scale' => $assetScale,
                'reversal_of_id' => $transaction->reversalOfId,
                'reason' => $transaction->reason,
                'immutable_metadata' => $transaction->immutableMetadata === []
                    ? null
                    : $this->canonicalPayload->json($transaction->immutableMetadata),
                'effective_at' => $effectiveAt,
                'posted_at' => null,
                'created_at' => $createdAt,
            ]);

            /** @var CustodyJournalEntry|null $entry */
            $entry = CustodyJournalEntry::query()
                ->where('idempotency_key', $transaction->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($inserted === 0) {
                if ($entry === null) {
                    throw new CustodyIdempotencyConflictException(
                        'Journal insertion conflicts with an existing effective reversal.',
                    );
                }

                if (! hash_equals($entry->canonical_payload_hash, $payloadHash)) {
                    throw new CustodyIdempotencyConflictException(
                        "Idempotency key [{$transaction->idempotencyKey}] has a different canonical payload.",
                    );
                }

                if ($entry->posted_at === null) {
                    throw new CustodyAccountingException(
                        'An idempotent journal replay found an incomplete transaction.',
                    );
                }

                return $entry->id;
            }

            if ($entry === null) {
                throw new CustodyAccountingException('Journal insertion did not return its durable entry.');
            }

            if ($transaction->reversalOfId !== null) {
                $this->lockAndValidateReversal(
                    $transaction->reversalOfId,
                    $assetKey,
                    $networkKey,
                    $assetScale,
                    $transaction->merchantId,
                );
            }

            $postingRows = [];
            foreach ($normalizedPostings as $line => $posting) {
                $postingRows[] = [
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line + 1,
                    'account_id' => $posting['account_id'],
                    'side' => $posting['side'],
                    'amount' => $posting['amount'],
                    'amount_atomic' => $posting['amount_atomic'],
                    'created_at' => $createdAt,
                ];
            }
            DB::table('custody_journal_postings')->insert($postingRows);

            $this->applyProjections(
                $entry->id,
                $accounts,
                $normalizedPostings,
                $beforeProjectionDelta,
            );

            $updated = DB::table('custody_journal_entries')
                ->where('id', $entry->id)
                ->whereNull('posted_at')
                ->update(['posted_at' => now('UTC')]);

            if ($updated !== 1) {
                throw new CustodyAccountingException('Journal entry could not make its posting transition.');
            }

            return $entry->id;
        }, 5);

        /** @var CustodyJournalEntry $entry */
        $entry = CustodyJournalEntry::query()
            ->with('postings')
            ->findOrFail($entryId);

        return $entry;
    }

    /**
     * @param  list<CustodyPostingData>  $postings
     * @return list<array{account_id: int, side: string, amount: string, amount_atomic: ?string}>
     */
    private function normalizePostings(array $postings, int $assetScale): array
    {
        return array_map(function (CustodyPostingData $posting) use ($assetScale): array {
            if (! in_array($posting->side, [CustodyAccount::SIDE_DEBIT, CustodyAccount::SIDE_CREDIT], true)) {
                throw new CustodyAccountingException('Journal posting side must be debit or credit.');
            }

            $amount = $this->decimal->positive($posting->amount, $assetScale);

            return [
                'account_id' => $posting->accountId,
                'side' => $posting->side,
                'amount' => $amount,
                'amount_atomic' => $this->decimal->atomic($amount, $assetScale, $posting->amountAtomic),
            ];
        }, $postings);
    }

    /**
     * @param  list<array{account_id: int, side: string, amount: string, amount_atomic: ?string}>  $postings
     */
    private function assertBalanced(array $postings): void
    {
        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();

        foreach ($postings as $posting) {
            if ($posting['side'] === CustodyAccount::SIDE_DEBIT) {
                $debits = $debits->plus($posting['amount']);
            } else {
                $credits = $credits->plus($posting['amount']);
            }
        }

        if ($debits->compareTo($credits) !== 0) {
            throw new CustodyAccountingException('Journal debits and credits must balance exactly.');
        }
    }

    /**
     * @param  Collection<int, CustodyAccount>  $accounts
     * @param  list<array{account_id: int, side: string, amount: string, amount_atomic: ?string}>  $postings
     * @param  (Closure(int, Collection<int, CustodyAccount>, Collection<int, object>, list<array{account_id: int, side: string, amount: string, amount_atomic: ?string}>): void)|null  $beforeProjectionDelta
     */
    private function applyProjections(
        int $entryId,
        Collection $accounts,
        array $postings,
        ?Closure $beforeProjectionDelta,
    ): void {
        $now = now('UTC');
        foreach ($accounts->keys()->sort()->values() as $accountId) {
            DB::table('custody_account_balances')->insertOrIgnore([
                'account_id' => $accountId,
                'balance' => '0',
                'last_journal_entry_id' => null,
                'revision' => 0,
                'rebuilt_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $projections = DB::table('custody_account_balances')
            ->whereIn('account_id', $accounts->keys()->all())
            ->orderBy('account_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('account_id');

        if ($beforeProjectionDelta !== null) {
            $beforeProjectionDelta($entryId, $accounts, $projections, $postings);
        }

        foreach ($accounts->keys()->sort()->values() as $accountId) {
            $account = $accounts->get($accountId);
            $projection = $projections->get($accountId);

            if ($account === null || $projection === null) {
                throw new CustodyAccountingException('Custody projection row is missing.');
            }

            $change = BigDecimal::zero();
            foreach ($postings as $posting) {
                if ($posting['account_id'] !== $accountId) {
                    continue;
                }

                $amount = BigDecimal::of($posting['amount']);
                $change = $posting['side'] === $account->normal_side
                    ? $change->plus($amount)
                    : $change->minus($amount);
            }

            $balance = BigDecimal::of((string) $projection->balance)->plus($change);
            if ($balance->isNegative()) {
                throw new CustodyAccountingException(
                    "Journal entry would make controlled custody account [{$accountId}] negative.",
                );
            }

            DB::table('custody_account_balances')
                ->where('account_id', $accountId)
                ->update([
                    'balance' => $this->decimal->storage($balance),
                    'last_journal_entry_id' => $entryId,
                    'revision' => (int) $projection->revision + 1,
                    'updated_at' => $now,
                ]);
        }
    }

    private function lockAndValidateReversal(
        int $reversalOfId,
        string $assetKey,
        string $networkKey,
        int $assetScale,
        ?int $merchantId,
    ): void {
        /** @var CustodyJournalEntry|null $target */
        $target = CustodyJournalEntry::query()
            ->whereKey($reversalOfId)
            ->lockForUpdate()
            ->first();

        if ($target === null || $target->posted_at === null) {
            throw new CustodyAccountingException('Reversal target must be an existing posted journal entry.');
        }

        $targetMerchantId = $target->merchant_id === null
            ? null
            : (int) $target->merchant_id;

        if ($targetMerchantId !== $merchantId) {
            throw new CustodyAccountingException('Reversal merchant must match the target journal entry merchant.');
        }

        if (
            $target->asset_key !== $assetKey
            || $target->network_key !== $networkKey
            || $target->asset_scale !== $assetScale
        ) {
            throw new CustodyAccountingException('Reversal target uses a different asset, network, or scale.');
        }

        if (CustodyJournalEntry::query()->where('reversal_of_id', $target->id)->whereNotNull('posted_at')->exists()) {
            throw new CustodyIdempotencyConflictException('Journal entry already has an effective reversal.');
        }
    }

    private function canonicalTime(?\DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)
            ->utc()
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function validateReference(string $value, string $label, int $maxLength): void
    {
        if ($value === '' || trim($value) !== $value || mb_strlen($value) > $maxLength) {
            throw new CustodyAccountingException("{$label} is empty, non-canonical, or too long.");
        }
    }

    private function validateOptionalReference(?string $value, string $label, int $maxLength): void
    {
        if ($value !== null) {
            $this->validateReference($value, $label, $maxLength);
        }
    }

    private function assertWritesEnabled(): void
    {
        if (
            config('custody.accounting_enabled', false) !== true
            || config('custody.journal_writes_enabled', false) !== true
        ) {
            throw new CustodyAccountingException('Custody journal writes are disabled.');
        }
    }
}
