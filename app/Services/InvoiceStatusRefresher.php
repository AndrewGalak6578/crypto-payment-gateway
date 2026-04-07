<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\EvmInvoiceMonitorInterface;
use App\Jobs\ForwardInvoiceJob;
use App\Models\Invoice;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use App\Support\Coin;
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
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
        private readonly EvmInvoiceMonitorInterface $evmMonitor
    ){}

    /**
     * Refreshes invoice payment state from chain data.
     *
     * Transitions:
     * - pending -> fixated
     * - pending -> expired
     * - pending|fixated|expired -> paid
     *
     * @param Invoice $invoice Invoice model instance to refresh.
     * @return Invoice Fresh invoice snapshot after transition handling.
     */
    public function refresh(Invoice $invoice): Invoice
    {
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

            $inv->received_all_coin = $receivedAll;
            $inv->received_conf_coin = $receivedConf;

            if (! $inv->first_txid && ! empty($txs)) {
                $first = $this->pickFirstTx($txs);
                if ($first) {
                    $inv->first_txid = (string) ($first['txid'] ?? null);
                    $inv->first_amount_coin = (string) ($first['amount'] ?? null);
                }
            }

            if ($inv->status === 'pending' && $receivedAll > 0.0) {
                $firstTime = $this->firstSeenTime($txs);

                $beforeExpiry = false;
                if ($firstTime !== null && $inv->expires_at) {
                    $beforeExpiry = Carbon::createFromTimestampUTC((int) $firstTime)->lte($inv->expires_at);
                } elseif ($inv->expires_at) {
                    $beforeExpiry = $now->lte($inv->expires_at);
                }

                if ($beforeExpiry) {
                    $inv->status = 'fixated';
                    $inv->fixated_at = $now;

                    $rate = (float) $this->rates->usd($assetKey);
                    $receivedUsd = $receivedAll * $rate;
                    $slip = $receivedUsd - (float) $inv->expected_usd;

                    $meta = is_array($inv->metadata) ? $inv->metadata : (array) ($inv->metadata ?? []);
                    $meta['slippage']['fixated_usd'] = $slip;
                    $meta['slippage']['fixated_rate_usd'] = $rate;
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
                    $rate = (float) $this->rates->usd($assetKey);
                    $paidUsd = $receivedConf * $rate;
                    $inv->paid_usd = $paidUsd;

                    $slip = $paidUsd - (float) $inv->expected_usd;

                    $meta = is_array($inv->metadata) ? $inv->metadata : (array) ($inv->metadata ?? []);
                    $meta['slippage']['paid_usd'] = $slip;
                    $meta['slippage']['paid_rate_usd'] = $rate;
                    $inv->metadata = $meta;
                }

                $eventsToDispatch[] = 'invoice.paid';
            }

            $confirmed = (float) ($inv->received_conf_coin ?? 0);
            $forwarded = (float) ($inv->forwarded_coin ?? 0);
            $scale = $this->assets->settlementScale($assetKey);
            $epsilon = $this->assets->epsilon($assetKey);
            $feePercent = (float) ($inv->merchant->fee_percent ?? 0.0);
            // Forwarding target must be based on merchant net amount, not gross received amount.
            $targetNet = $this->norm($confirmed - ($confirmed * ($feePercent / 100)), $scale);
            $targetNet = max(0.0, $targetNet);
            $remainingNet = $this->norm($targetNet - $forwarded, $scale);

            if (
                $inv->status === 'paid'
                && $remainingNet > $epsilon
                && in_array($inv->forward_status, ['none', 'partial', 'failed'], true)
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

        if ($shouldDispatchForward && config('forwarding.enabled')) {
            ForwardInvoiceJob::dispatch($invoice->id);
        }

        return $fresh;
    }

    /**
     * Checks whether confirmed amount satisfies paid threshold.
     *
     * @param Invoice $inv Invoice snapshot under lock.
     * @param float $receivedConf Confirmed amount on chain.
     * @return bool
     */
    private function isPaid(Invoice $inv, float $receivedConf): bool
    {
        $pct = (float)config('payments.slippage.paid_coin_percent', 0.5);
        $expected = (float)$inv->amount_coin;

        // need >= expected * (1 - pct/100)
        $need = $expected * max(0.0, (1.0 - $pct / 100.0));

        // micro epsilon against float whitenoise
        return $receivedConf + 1e-12 >= $need;
    }

    /**
     * Picks earliest known transaction for invoice address.
     *
     * @param array<int, array<string, mixed>> $txs
     * @return array<string, mixed>|null
     */
    private function pickFirstTx(array $txs): ?array
    {
        $best = null;
        foreach ($txs as $tx) {
            $t = $tx['time'] ?? null;
            if ($t == null) continue;
            if ($best == null || (int)$t < ($best['time'] ?? PHP_INT_MAX)) {
                $best = $tx;
            }
        }
        return $best ?? ($txs[0] ?? null);
    }

    /**
     * Returns first seen transaction timestamp in UTC seconds.
     *
     * @param array<int, array<string, mixed>> $txs
     * @return int|null Unix timestamp.
     */
    private function firstSeenTime(array $txs): ?int
    {
        $first = $this->pickFirstTx($txs);
        if (!$first) return  null;
        return (int)$first['time'] ?? null;
    }


    /**
     * Rounds value to configured coin precision.
     *
     * @param float $value Value to normalize.
     * @param int $scale Number of decimal places for target coin.
     */
    private function norm(float $value, int $scale): float
    {
        return round($value, $scale);
    }

    /**
     * Collects UTXO chains state
     *
     * @param Invoice $invoice
     * @param int $confirmations
     * @return array
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
            'received_all' => (float)($totals['all'] ?? 0.0),
            'received_confirmed' => (float)($totals['confirmed'] ?? 0.0),
        ];
    }

    private function collectEvmState(Invoice $invoice, int $confirmations): array
    {
        $result = $this->evmMonitor->detect($invoice, $confirmations);

        return [
            'txs' => $result->transactions,
            'received_all' => (float) $result->receivedAllDecimal,
            'received_confirmed' => (float) $result->receivedConfirmedDecimal,
        ];
    }

}
