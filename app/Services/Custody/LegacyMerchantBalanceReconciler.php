<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Models\MerchantSettlementEntry;
use App\Support\Assets\AssetRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class LegacyMerchantBalanceReconciler
{
    public const EXACT = 'exact_match';

    public const BALANCE_WITHOUT_CREDIT = 'balance_without_completed_internal_credit';

    public const CREDIT_WITHOUT_BALANCE = 'completed_internal_credit_without_balance';

    public const BALANCE_EXCEEDS_CREDIT = 'balance_exceeds_completed_internal_credit';

    public const CREDIT_EXCEEDS_BALANCE = 'completed_internal_credit_exceeds_balance';

    public const UNKNOWN_ASSET = 'unknown_asset';

    public const NETWORK_MISMATCH = 'network_mismatch_or_ambiguous';

    public const NEGATIVE_BALANCE = 'negative_legacy_balance';

    public const NEGATIVE_CREDIT = 'negative_completed_internal_credit';

    public function __construct(
        private AssetRegistry $assets,
        private CustodyDecimal $decimal,
    ) {}

    public function reconcile(?int $merchantId = null, ?string $assetKey = null): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->reconcileSnapshot($merchantId, $assetKey);
        }

        return DB::transaction(function () use ($merchantId, $assetKey): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->reconcileSnapshot($merchantId, $assetKey);
        });
    }

    /**
     * @return array{
     *   checked: int,
     *   mismatch_count: int,
     *   classifications: array<string, int>,
     *   rows: list<array<string, mixed>>
     * }
     */
    private function reconcileSnapshot(?int $merchantId, ?string $assetKey): array
    {
        $assetKey = $assetKey === null || $assetKey === '' ? null : strtolower($assetKey);
        $balances = DB::table('merchant_balances')
            ->when($merchantId !== null, fn ($query) => $query->where('merchant_id', $merchantId))
            ->when($assetKey !== null, fn ($query) => $query->where('coin', $assetKey))
            ->orderBy('merchant_id')
            ->orderBy('coin')
            ->get(['merchant_id', 'coin', 'amount']);
        $credits = DB::table('merchant_settlement_entries')
            ->where('type', MerchantSettlementEntry::TYPE_INTERNAL_CREDIT)
            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->when($merchantId !== null, fn ($query) => $query->where('merchant_id', $merchantId))
            ->when($assetKey !== null, fn ($query) => $query->where('asset_key', $assetKey))
            ->orderBy('merchant_id')
            ->orderBy('asset_key')
            ->orderBy('id')
            ->get(['merchant_id', 'asset_key', 'network_key', 'amount_coin']);

        $groupedBalances = [];
        foreach ($balances as $balance) {
            $groupedBalances[$this->key((int) $balance->merchant_id, (string) $balance->coin)] = $balance;
        }

        $groupedCredits = [];
        foreach ($credits as $credit) {
            $key = $this->key((int) $credit->merchant_id, (string) $credit->asset_key);
            $groupedCredits[$key] ??= [
                'merchant_id' => (int) $credit->merchant_id,
                'asset_key' => (string) $credit->asset_key,
                'total' => BigDecimal::zero(),
                'networks' => [],
                'entry_count' => 0,
            ];
            $groupedCredits[$key]['total'] = $groupedCredits[$key]['total']
                ->plus(BigDecimal::of((string) $credit->amount_coin));
            $groupedCredits[$key]['networks'][] = $credit->network_key;
            $groupedCredits[$key]['entry_count']++;
        }

        $keys = array_unique([...array_keys($groupedBalances), ...array_keys($groupedCredits)]);
        sort($keys, SORT_STRING);
        $rows = [];
        $classifications = [];

        foreach ($keys as $key) {
            $balance = $groupedBalances[$key] ?? null;
            $credit = $groupedCredits[$key] ?? null;
            [$rowMerchantId, $rowAssetKey] = explode(':', $key, 2);
            $balanceAmount = $balance === null
                ? BigDecimal::zero()
                : BigDecimal::of((string) $balance->amount);
            $creditAmount = $credit['total'] ?? BigDecimal::zero();
            $networks = array_values(array_unique($credit['networks'] ?? [], SORT_REGULAR));
            $classification = $this->classify(
                assetKey: $rowAssetKey,
                hasBalance: $balance !== null,
                hasCredits: $credit !== null,
                balance: $balanceAmount,
                credits: $creditAmount,
                networks: $networks,
            );
            $classifications[$classification] = ($classifications[$classification] ?? 0) + 1;

            $rows[] = [
                'merchant_id' => (int) $rowMerchantId,
                'asset_key' => $rowAssetKey,
                'registry_network_key' => $this->registryNetwork($rowAssetKey),
                'settlement_network_keys' => $networks,
                'merchant_balance' => $this->decimal->storage($balanceAmount),
                'completed_internal_credit_total' => $this->decimal->storage($creditAmount),
                'completed_internal_credit_count' => $credit['entry_count'] ?? 0,
                'difference_balance_minus_credit' => $this->decimal->storage(
                    $balanceAmount->minus($creditAmount),
                ),
                'classification' => $classification,
                'matches' => $classification === self::EXACT,
            ];
        }

        ksort($classifications, SORT_STRING);

        return [
            'checked' => count($rows),
            'mismatch_count' => count(array_filter(
                $rows,
                fn (array $row): bool => ! $row['matches'],
            )),
            'classifications' => $classifications,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string|null>  $networks
     */
    private function classify(
        string $assetKey,
        bool $hasBalance,
        bool $hasCredits,
        BigDecimal $balance,
        BigDecimal $credits,
        array $networks,
    ): string {
        $registryNetwork = $this->registryNetwork($assetKey);
        if ($registryNetwork === null) {
            return self::UNKNOWN_ASSET;
        }

        if (
            $hasCredits
            && (count($networks) !== 1 || $networks[0] === null || $networks[0] !== $registryNetwork)
        ) {
            return self::NETWORK_MISMATCH;
        }

        if ($balance->isNegative()) {
            return self::NEGATIVE_BALANCE;
        }

        if ($credits->isNegative()) {
            return self::NEGATIVE_CREDIT;
        }

        if (! $hasBalance) {
            return self::CREDIT_WITHOUT_BALANCE;
        }

        if (! $hasCredits) {
            return self::BALANCE_WITHOUT_CREDIT;
        }

        return match ($balance->compareTo($credits)) {
            0 => self::EXACT,
            1 => self::BALANCE_EXCEEDS_CREDIT,
            -1 => self::CREDIT_EXCEEDS_BALANCE,
        };
    }

    private function registryNetwork(string $assetKey): ?string
    {
        try {
            return $this->assets->network($assetKey);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function key(int $merchantId, string $assetKey): string
    {
        return "{$merchantId}:{$assetKey}";
    }
}
