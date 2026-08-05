<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EvmInvoiceMonitorInterface;
use App\Jobs\ForwardInvoiceJob;
use App\Models\Invoice;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\Forwarding\ForwardingGate;
use App\Services\Settlement\SettlementAmountCalculator;
use App\Services\Settlement\SettlementDecimal;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use App\Support\Coin;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recalculates invoice blockchain state and emits state transition webhooks.
 */
final class InvoiceStatusRefresher
{
    public function __construct(
        private CoinRate $rates,
        private EnqueueInvoiceWebhook $enqueueWebhook,
        private readonly SettlementAmountCalculator $amounts,
        private readonly SettlementDecimal $decimal,
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
        private readonly EvmInvoiceMonitorInterface $evmMonitor,
        private readonly ForwardingGate $forwardingGate,
    ) {}

    /**
     * Refreshes invoice payment state from chain data.
     *
     * Transitions:
     * - pending -> fixated
     * - pending -> expired
     * - pending|fixated|expired -> paid
     *
     * @param  Invoice  $invoice  Invoice model instance to refresh.
     * @return Invoice Fresh invoice snapshot after transition handling.
     */
    public function refresh(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'awaiting_asset') {
            if ($invoice->expires_at && now('UTC')->gt($invoice->expires_at)) {
                $invoice->forceFill(['status' => 'expired'])->save();

                return $invoice->fresh(['merchant']);
            }

            return $invoice->fresh(['merchant']);
        }

        $shouldDispatchForward = false;
        $eventsToDispatch = [];

        DB::transaction(function () use ($invoice, &$shouldDispatchForward, &$eventsToDispatch): void {
            /** @var Invoice $inv */
            $inv = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $assetKey = $inv->resolvedAssetKey();
            $now = now('UTC');
            $confirmations = (int) config('payments.confirmations', 1);

            $networkKey = $inv->resolvedNetworkKey();
            $family = $this->chains->family($networkKey);

            $state = match ($family) {
                'utxo' => $this->collectUtxoState($inv, $confirmations),
                'evm' => $this->collectEvmState($inv, $confirmations),
                default => throw new RuntimeException("Unsupported chain family [{$family}] for invoice refresh."),
            };

            $txs = $state['txs'];
            $receivedAll = $state['received_all'];
            $receivedConf = $state['received_confirmed'];

            $firstTxId = $state['first_txid'] ?? null;
            $firstAmount = $state['first_amount'] ?? null;
            $firstSeenAt = $state['first_seen_at'] ?? null;

            $inv->received_all_coin = $receivedAll;
            $inv->received_conf_coin = $receivedConf;

            if (! $inv->first_txid) {
                if ($family === 'evm' && $firstTxId !== null) {
                    $inv->first_txid = (string) $firstTxId;
                    $inv->first_amount_coin = $firstAmount !== null ? (string) $firstAmount : null;
                } elseif (! empty($txs)) {
                    $first = $this->pickFirstTx($txs);
                    if ($first) {
                        $inv->first_txid = (string) ($first['txid'] ?? null);
                        $inv->first_amount_coin = (string) ($first['amount'] ?? null);
                    }
                }
            }

            if ($inv->status === 'pending' && BigDecimal::of($receivedAll)->compareTo(BigDecimal::zero()) > 0) {
                $firstTime = $family === 'evm'
                    ? $firstSeenAt
                    : $this->firstSeenTime($txs);

                $beforeExpiry = false;
                if ($firstTime !== null && $inv->expires_at) {
                    $beforeExpiry = Carbon::createFromTimestampUTC((int) $firstTime)->lte($inv->expires_at);
                } elseif ($inv->expires_at) {
                    $beforeExpiry = $now->lte($inv->expires_at);
                }

                if ($beforeExpiry) {
                    $inv->status = 'fixated';
                    $inv->fixated_at = $now;

                    $rate = BigDecimal::of((string) $this->rates->usd($assetKey));
                    $receivedUsd = BigDecimal::of($receivedAll)
                        ->multipliedBy($rate)
                        ->toScale(2, RoundingMode::HALF_UP);
                    $slip = $receivedUsd->minus(BigDecimal::of((string) $inv->expected_usd));

                    $meta = is_array($inv->metadata) ? $inv->metadata : (array) ($inv->metadata ?? []);
                    $meta['slippage']['fixated_usd'] = (string) $slip;
                    $meta['slippage']['fixated_rate_usd'] = (string) $rate;
                    $inv->metadata = $meta;

                    $eventsToDispatch[] = 'invoice.fixated';
                }
            }

            if ($inv->status === 'pending' && $inv->expires_at && ! $inv->fixated_at && $now->gt($inv->expires_at)) {
                $inv->status = 'expired';
                $eventsToDispatch[] = 'invoice.expired';
            }

            if (in_array($inv->status, ['pending', 'fixated', 'expired'], true) && $this->isPaid($inv, $receivedConf)) {
                $inv->status = 'paid';
                $inv->paid_at = $inv->paid_at ?? $now;

                if ($inv->paid_usd === null) {
                    $rate = BigDecimal::of((string) $this->rates->usd($assetKey));
                    $paidUsd = BigDecimal::of($receivedConf)
                        ->multipliedBy($rate)
                        ->toScale(2, RoundingMode::HALF_UP);
                    $inv->paid_usd = (string) $paidUsd;

                    $slip = $paidUsd->minus(BigDecimal::of((string) $inv->expected_usd));

                    $meta = is_array($inv->metadata) ? $inv->metadata : (array) ($inv->metadata ?? []);
                    $meta['slippage']['paid_usd'] = (string) $slip;
                    $meta['slippage']['paid_rate_usd'] = (string) $rate;
                    $inv->metadata = $meta;
                }

                $this->applySettlementSnapshot($inv, $receivedConf, $assetKey);

                $eventsToDispatch[] = 'invoice.paid';
            }

            $recordedForwarded = $this->amounts->recordedForwardedCoin($inv);
            if (
                $this->decimal->asset($recordedForwarded, $assetKey)
                    ->compareTo($this->decimal->asset($inv->forwarded_coin, $assetKey)) > 0
            ) {
                $inv->forwarded_coin = $recordedForwarded;
            }

            if (
                $inv->status === 'paid'
                && $inv->settlement_snapshot_locked_at === null
                && in_array($inv->forward_status, [
                    Invoice::FORWARD_STATUS_NONE,
                    Invoice::FORWARD_STATUS_PROCESSING,
                    Invoice::FORWARD_STATUS_PARTIAL,
                    Invoice::FORWARD_STATUS_FAILED,
                ], true)
            ) {
                $inv->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
            }

            if (
                $inv->status === 'paid'
                && $inv->forward_status === Invoice::FORWARD_STATUS_FAILED
                && ! $inv->hasRetryableForwardStatus()
            ) {
                $inv->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
            }

            $remainingNet = $this->amounts->remainingPayoutCoin($inv);

            if (
                $inv->status === 'paid'
                && BigDecimal::of($remainingNet)->compareTo(BigDecimal::zero()) > 0
                && $inv->hasRetryableForwardStatus()
                && $inv->forward_attempt_uuid === null
            ) {
                $shouldDispatchForward = true;
            }

            $inv->save();
        });

        $fresh = $invoice->fresh(['merchant']);

        foreach ($eventsToDispatch as $event) {
            $this->enqueueWebhook->enqueue($event, $fresh);
        }

        if ($shouldDispatchForward) {
            $gateState = $this->forwardingGate->inspect();
            $this->forwardingGate->throwIfOperationalFailure($gateState);

            if ($gateState->effective()) {
                ForwardInvoiceJob::dispatch($invoice->id);
            }
        }

        return $fresh;
    }

    /**
     * Checks whether confirmed amount satisfies paid threshold.
     *
     * @param  Invoice  $inv  Invoice snapshot under lock.
     * @param  string  $receivedConf  Confirmed amount on chain.
     */
    private function isPaid(Invoice $inv, string $receivedConf): bool
    {
        $pct = BigDecimal::of((string) config('payments.slippage.paid_coin_percent', '0.5'));
        $multiplier = BigDecimal::one()
            ->minus($pct->dividedBy('100', 18, RoundingMode::HALF_UP));
        $need = BigDecimal::of((string) $inv->amount_coin)->multipliedBy(
            $multiplier->compareTo(BigDecimal::zero()) > 0 ? $multiplier : BigDecimal::zero(),
        );

        return BigDecimal::of($receivedConf)->compareTo($need) >= 0;
    }

    private function applySettlementSnapshot(Invoice $inv, string $receivedConf, string $assetKey): void
    {
        if ($inv->settlement_snapshot_locked_at !== null) {
            return;
        }

        $grossCoin = $this->decimal->asset($receivedConf, $assetKey);
        $feePercent = (string) ($inv->merchant->getRawOriginal('fee_percent') ?? '0');
        $feeCoin = $this->decimal->percentage($grossCoin, $feePercent, $assetKey);
        $payoutCoin = $this->decimal->positiveOrZero($grossCoin->minus($feeCoin), $assetKey);
        $paidUsd = BigDecimal::of((string) ($inv->paid_usd ?? '0'));
        $feeUsd = $paidUsd->multipliedBy(BigDecimal::of($feePercent))
            ->dividedBy('100', 2, RoundingMode::HALF_UP);
        $payoutUsd = $paidUsd->minus($feeUsd)->toScale(2, RoundingMode::HALF_UP);

        if ($inv->fee_coin === null) {
            $inv->fee_coin = (string) $feeCoin;
        }

        if ($inv->merchant_payout_coin === null) {
            $inv->merchant_payout_coin = (string) $payoutCoin;
        }

        if ($inv->fee_usd === null) {
            $inv->fee_usd = (string) $feeUsd;
        }

        if ($inv->merchant_payout_usd === null) {
            $inv->merchant_payout_usd = (string) $payoutUsd;
        }

        $inv->settlement_snapshot_locked_at = now('UTC');
    }

    /**
     * Picks earliest known transaction for invoice address.
     *
     * @param  array<int, array<string, mixed>>  $txs
     * @return array<string, mixed>|null
     */
    private function pickFirstTx(array $txs): ?array
    {
        $best = null;
        foreach ($txs as $tx) {
            $t = $tx['time'] ?? null;
            if ($t == null) {
                continue;
            }
            if ($best == null || (int) $t < ($best['time'] ?? PHP_INT_MAX)) {
                $best = $tx;
            }
        }

        return $best ?? ($txs[0] ?? null);
    }

    /**
     * Returns first seen transaction timestamp in UTC seconds.
     *
     * @param  array<int, array<string, mixed>>  $txs
     * @return int|null Unix timestamp.
     */
    private function firstSeenTime(array $txs): ?int
    {
        $first = $this->pickFirstTx($txs);
        if (! $first) {
            return null;
        }

        return (int) $first['time'] ?? null;
    }

    /**
     * Collects UTXO chains state
     */
    private function collectUtxoState(Invoice $invoice, int $confirmations): array
    {
        $assetKey = $invoice->resolvedAssetKey();
        $rpc = Coin::rpc($assetKey);
        $label = "inv:{$invoice->public_id}";

        $txs = $rpc->getTransactionsByAddress($invoice->pay_address, 0, 1000, $label);
        $totals = $rpc->getReceivedTotals($invoice->pay_address, $confirmations);

        return [
            'txs' => $txs,
            'received_all' => (string) ($totals['all'] ?? '0'),
            'received_confirmed' => (string) ($totals['confirmed'] ?? '0'),
        ];
    }

    private function collectEvmState(Invoice $invoice, int $confirmations): array
    {
        $result = $this->evmMonitor->detect($invoice, $confirmations);

        return [
            'txs' => $result->transactions,
            'received_all' => $result->receivedAllDecimal,
            'received_confirmed' => $result->receivedConfirmedDecimal,
            'first_txid' => $result->firstTxHash,
            'first_amount' => $result->firstAmountDecimal,
            'first_seen_at' => $result->firstSeenAt,
        ];
    }
}
