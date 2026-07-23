<?php

declare(strict_types=1);

namespace App\Services\MerchantPortal;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantBalance;
use App\Models\SuperWallet;
use App\Models\WebhookDelivery;
use App\Support\Assets\AssetRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class DashboardMetricsService
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly AssetRegistry $assets) {}

    /**
     * @param  array<string, bool>  $access
     * @return array<string, mixed>
     */
    public function forMerchant(int $merchantId, array $access = []): array
    {
        $now = CarbonImmutable::now();
        $periodFrom = $now->startOfMonth();
        $comparisonFrom = $periodFrom->subMonthNoOverflow();
        $comparisonTo = $comparisonFrom->addSeconds($periodFrom->diffInSeconds($now));

        $cacheKey = sprintf(
            'merchant:%d:dashboard:v2:%s:%s',
            $merchantId,
            $now->format('Y-m-d-H-i'),
            md5(json_encode($access, JSON_THROW_ON_ERROR))
        );

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($merchantId, $access, $now, $periodFrom, $comparisonFrom, $comparisonTo): array {
            $paidCurrent = $this->paidInvoicesForPeriod($merchantId, $periodFrom, $now);
            $paidPrevious = $this->paidInvoicesForPeriod($merchantId, $comparisonFrom, $comparisonTo);
            $receivedUsd = $this->sumUsd($paidCurrent);
            $previousReceivedUsd = $this->sumUsd($paidPrevious);
            $inFlight = $this->inFlight($merchantId);
            $underpaid = $this->underpaid($merchantId);
            $forwarded = $this->forwardedForPeriod($merchantId, $periodFrom, $now);
            $wallet = $this->walletEstimate($merchantId);
            $webhookFailureCount = $this->webhookFailureCount($merchantId);
            $forwardingFailureCount = Invoice::query()
                ->where('merchant_id', $merchantId)
                ->where('forward_status', 'failed')
                ->count();

            return [
                'period' => [
                    'from' => $periodFrom->toIso8601String(),
                    'to' => $now->toIso8601String(),
                    'comparison_from' => $comparisonFrom->toIso8601String(),
                    'comparison_to' => $comparisonTo->toIso8601String(),
                    'label' => $now->format('F Y'),
                    'comparison_label' => $comparisonFrom->format('F Y').' same period',
                ],
                'metrics' => [
                    'received_month_usd' => $this->money($receivedUsd),
                    'received_month_change_percent' => $this->changePercent($receivedUsd, $previousReceivedUsd),
                    'paid_count' => $paidCurrent->count(),
                    'previous_received_month_usd' => $this->money($previousReceivedUsd),
                    'in_flight_usd' => $this->money($inFlight['usd']),
                    'awaiting_count' => $inFlight['awaiting_count'],
                    'confirming_count' => $inFlight['confirming_count'],
                    'underpaid_count' => $underpaid['count'],
                    'underpaid_missing_usd' => $this->money($underpaid['missing_usd']),
                    'forwarded_month_usd' => $this->money($this->sumUsd($forwarded)),
                    'forwarded_count' => $forwarded->count(),
                    'forwarding_failed_count' => $forwardingFailureCount,
                    'wallet_estimate_usd' => $this->money($wallet['estimate_usd']),
                    'wallet_estimate_partial' => $wallet['partial'],
                    'wallet_asset_count' => count($wallet['balances']),
                    'needs_attention_count' => $underpaid['count'] + $webhookFailureCount + $forwardingFailureCount + $this->expiredWithFundsCount($merchantId),
                ],
                'asset_breakdown' => $this->assetBreakdown($paidCurrent),
                'wallet_balances' => $wallet['balances'],
                'attention' => $this->attention($merchantId, $underpaid['count'], $webhookFailureCount, $forwardingFailureCount, (bool) ($access['webhooks.read'] ?? false)),
                'recent_payments' => $this->recentPayments($merchantId),
                'integration_health' => $this->integrationHealth($merchantId, $webhookFailureCount),
                'computed_at' => $now->toIso8601String(),
                'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
            ];
        });
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function paidInvoicesForPeriod(int $merchantId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Invoice::query()
            ->where('merchant_id', $merchantId)
            ->where('status', 'paid')
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($fallback) use ($from, $to): void {
                        $fallback
                            ->whereNull('paid_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->get();
    }

    /**
     * @return array{usd: float, awaiting_count: int, confirming_count: int}
     */
    private function inFlight(int $merchantId): array
    {
        $invoices = Invoice::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['awaiting_asset', 'pending', 'fixated', 'confirming'])
            ->get(['status', 'expected_usd']);

        return [
            'usd' => $this->sumUsd($invoices),
            'awaiting_count' => $invoices->whereIn('status', ['awaiting_asset', 'pending'])->count(),
            'confirming_count' => $invoices->whereIn('status', ['fixated', 'confirming'])->count(),
        ];
    }

    /**
     * @return array{count: int, missing_usd: float}
     */
    private function underpaid(int $merchantId): array
    {
        $invoices = Invoice::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['pending', 'fixated', 'confirming'])
            ->where('received_all_coin', '>', 0)
            ->whereColumn('received_all_coin', '<', 'amount_coin')
            ->get(['amount_coin', 'received_all_coin', 'expected_usd']);

        $missingUsd = $invoices->sum(function (Invoice $invoice): float {
            $amount = (float) $invoice->amount_coin;
            if ($amount <= 0) {
                return 0.0;
            }

            $remainingRatio = max(0.0, ($amount - (float) $invoice->received_all_coin) / $amount);

            return $this->invoiceUsd($invoice) * $remainingRatio;
        });

        return [
            'count' => $invoices->count(),
            'missing_usd' => (float) $missingUsd,
        ];
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function forwardedForPeriod(int $merchantId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Invoice::query()
            ->where('merchant_id', $merchantId)
            ->whereBetween('last_forwarded_at', [$from, $to])
            ->whereIn('forward_status', ['done', 'partial'])
            ->get(['expected_usd', 'paid_usd', 'merchant_payout_usd']);
    }

    /**
     * @return array{estimate_usd: float, partial: bool, balances: array<int, array<string, mixed>>}
     */
    private function walletEstimate(int $merchantId): array
    {
        $balances = MerchantBalance::query()
            ->where('merchant_id', $merchantId)
            ->orderBy('coin')
            ->get(['coin', 'amount', 'updated_at']);

        $rows = [];
        $total = 0.0;
        $partial = false;

        foreach ($balances as $balance) {
            $assetKey = strtolower((string) $balance->coin);
            $networkKey = null;

            try {
                $networkKey = $this->assets->network($assetKey);
            } catch (RuntimeException) {
                $partial = true;
            }

            $rate = $this->latestRateUsd($merchantId, $assetKey);
            $amount = (float) $balance->amount;
            $estimatedUsd = $rate !== null ? $amount * $rate : null;

            if ($estimatedUsd === null) {
                $partial = true;
            } else {
                $total += $estimatedUsd;
            }

            $rows[] = [
                'coin' => strtoupper((string) $balance->coin),
                'asset_key' => $assetKey,
                'network_key' => $networkKey,
                'amount' => (string) $balance->amount,
                'rate_usd' => $rate !== null ? $this->money($rate, 8) : null,
                'estimated_usd' => $estimatedUsd !== null ? $this->money($estimatedUsd) : null,
                'updated_at' => optional($balance->updated_at)->toIso8601String(),
            ];
        }

        return [
            'estimate_usd' => $total,
            'partial' => $partial,
            'balances' => $rows,
        ];
    }

    private function latestRateUsd(int $merchantId, string $assetKey): ?float
    {
        $rate = Invoice::query()
            ->where('merchant_id', $merchantId)
            ->where(function ($query) use ($assetKey): void {
                $query
                    ->where('asset_key', $assetKey)
                    ->orWhere('coin', strtoupper($assetKey))
                    ->orWhere('coin', strtolower($assetKey));
            })
            ->where('rate_usd', '>', 0)
            ->latest('id')
            ->value('rate_usd');

        return $rate !== null ? (float) $rate : null;
    }

    private function webhookFailureCount(int $merchantId): int
    {
        return WebhookDelivery::query()
            ->where(function ($query) use ($merchantId): void {
                $query
                    ->where('merchant_id', $merchantId)
                    ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('merchant_id', $merchantId));
            })
            ->where('status', 'failed')
            ->count();
    }

    private function expiredWithFundsCount(int $merchantId): int
    {
        return Invoice::query()
            ->where('merchant_id', $merchantId)
            ->where('status', 'expired')
            ->where('received_all_coin', '>', 0)
            ->count();
    }

    /**
     * @param  Collection<int, Invoice>  $paidCurrent
     * @return array<int, array<string, mixed>>
     */
    private function assetBreakdown(Collection $paidCurrent): array
    {
        return $paidCurrent
            ->groupBy(fn (Invoice $invoice): string => strtolower((string) ($invoice->asset_key ?: $invoice->coin ?: 'unknown')))
            ->map(function (Collection $items, string $assetKey): array {
                $networkKey = null;
                try {
                    $networkKey = $this->assets->network($assetKey);
                } catch (RuntimeException) {
                    // Unknown historical asset; keep it visible without registry metadata.
                }

                return [
                    'asset_key' => $assetKey,
                    'coin' => strtoupper($assetKey),
                    'network_key' => $networkKey,
                    'received_usd' => $this->money($this->sumUsd($items)),
                    'paid_count' => $items->count(),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['received_usd'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attention(int $merchantId, int $underpaidCount, int $webhookFailureCount, int $forwardingFailureCount, bool $includeWebhookDetails): array
    {
        $expiredWithFunds = $this->expiredWithFundsCount($merchantId);

        $items = [
            [
                'type' => 'underpaid',
                'tone' => $underpaidCount > 0 ? 'warning' : 'neutral',
                'count' => $underpaidCount,
                'title' => 'Underpaid payments',
                'body' => 'Payers sent less than required.',
            ],
            [
                'type' => 'expired_with_funds',
                'tone' => $expiredWithFunds > 0 ? 'danger' : 'neutral',
                'count' => $expiredWithFunds,
                'title' => 'Expired with funds',
                'body' => 'Expired payments received funds and may need support.',
            ],
            [
                'type' => 'forwarding_failed',
                'tone' => $forwardingFailureCount > 0 ? 'danger' : 'neutral',
                'count' => $forwardingFailureCount,
                'title' => 'Forwarding failed',
                'body' => 'Settlement forwarding needs review.',
            ],
        ];

        if ($includeWebhookDetails) {
            $items[] = [
                'type' => 'webhook_failed',
                'tone' => $webhookFailureCount > 0 ? 'danger' : 'neutral',
                'count' => $webhookFailureCount,
                'title' => 'Webhook failures',
                'body' => 'Merchant integration did not receive one or more events.',
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentPayments(int $merchantId): array
    {
        return Invoice::query()
            ->where('merchant_id', $merchantId)
            ->latest('id')
            ->limit(8)
            ->get([
                'id',
                'public_id',
                'external_id',
                'status',
                'coin',
                'asset_key',
                'network_key',
                'amount_coin',
                'expected_usd',
                'received_conf_coin',
                'received_all_coin',
                'forward_status',
                'created_at',
                'paid_at',
            ])
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
                'asset_key' => $invoice->asset_key,
                'network_key' => $invoice->network_key,
                'amount_coin' => $invoice->formattedCoinAmount('amount_coin'),
                'expected_usd' => (string) $invoice->expected_usd,
                'received_conf_coin' => $invoice->formattedCoinAmount('received_conf_coin'),
                'received_all_coin' => $invoice->formattedCoinAmount('received_all_coin'),
                'forward_status' => $invoice->forward_status,
                'created_at' => optional($invoice->created_at)->toIso8601String(),
                'paid_at' => optional($invoice->paid_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationHealth(int $merchantId, int $webhookFailureCount): array
    {
        $merchant = Merchant::query()->find($merchantId);
        $activeApiKeys = MerchantApiKey::query()
            ->where('merchant_id', $merchantId)
            ->whereNull('revoked_at')
            ->count();
        $walletCount = SuperWallet::query()
            ->where('merchant_id', $merchantId)
            ->count();

        return [
            'api_keys_ready' => $activeApiKeys > 0,
            'active_api_keys_count' => $activeApiKeys,
            'webhook_ready' => ! empty($merchant?->webhook_url) && ! empty($merchant?->webhook_secret),
            'webhook_url' => $merchant?->webhook_url,
            'recent_webhook_failures' => $webhookFailureCount,
            'settlement_wallet_ready' => $walletCount > 0,
            'settlement_wallet_count' => $walletCount,
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function sumUsd(Collection $invoices): float
    {
        return (float) $invoices->sum(fn (Invoice $invoice): float => $this->invoiceUsd($invoice));
    }

    private function invoiceUsd(Invoice $invoice): float
    {
        return (float) ($invoice->merchant_payout_usd ?? $invoice->paid_usd ?? $invoice->expected_usd ?? 0);
    }

    private function changePercent(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function money(float $value, int $scale = 2): string
    {
        return number_format($value, $scale, '.', '');
    }
}
