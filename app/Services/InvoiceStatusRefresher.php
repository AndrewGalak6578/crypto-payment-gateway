<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Support\Coin;
use Illuminate\Support\Carbon;

final class InvoiceStatusRefresher
{
    public function __construct(private CoinRate $rates){}

    public function refresh(Invoice $invoice): Invoice
    {
        /** @var Invoice $inv */
        $inv = Invoice::query()
            ->whereKey($invoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $now = now('UTC');

        $confirmations = (int)config('payments.confirmations', 1);

        $rpc = Coin::rpc($inv->coin);

        // label same as InvoiceCreator: inv{public_id}
        $label = "inv:{$inv->public_id}";

        // tx list: all transactions, both confirmed and unconfirmed to fixate first_tx
        $txs = $rpc->getTransactionsByAddress($inv->pay_address, 0, 1000, $label);

        // totals: all + confirmed(minConf = $confirmations)
        $totals = $rpc->getReceivedTotals($inv->pay_address, $confirmations);

        $receivedAll = (float)($totals['all'] ?? 0.0);
        $receivedConf = (float)($totals['confirmed'] ?? 0.0);

        $inv->received_all_coin = $receivedAll;
        $inv->received_conf_coin = $receivedConf;

        // first tx snapshot (only once)
        if (!$inv->first_txid && !empty($txs)) {
            $first = $this->pickFirstTx($txs);
            if ($first) {
                $inv->first_txid = (string)($first['txid'] ?? null);
                $inv->first_amount_coin = (float)($first['amount'] ?? null);
            }
        }

        // ---------TRANSITIONS-----------
        // 1 - fixated - if any amount came BEFORE expires_at (by the time of the first transaction)
        if ($inv->status === 'pending' && $receivedAll > 0.0) {
            $firstTime = $this->firstSeenTime($txs); // unix time|null

            $beforeExpiry = false;
            if ($firstTime !== null && $inv->expires_at) {
                $beforeExpiry = Carbon::createFromTimestampUTC((int)$firstTime)
                    ->lte($inv->expires_at);
            } elseif ($inv->expires_at) {
                $beforeExpiry = $now->lte($inv->expires_at);
            }

            if ($beforeExpiry) {
                $inv->status = "fixated";
                $inv->fixated_at = $now;

                $rate = (float)$this->rates->usd($inv->coin);
                $receivedUsd = $receivedAll * $rate;
                $slip = $receivedUsd - (float)$inv->expected_usd;

                $meta = is_array($inv->metadata) ? $inv->metadata : (array)($inv->metadata ?? []);
                $meta['slippage']['fixated_usd'] = $slip;
                $meta['slippage']['fixated_rate_usd'] = $rate;
                $inv->metadata = $meta;
            }
        }

        // 2 - expired - if pending and now > expires_at and NO FIXATION
        if ($inv->status === 'pending' && $inv->expires_at && !$inv->fixated_at && $now->gt($inv->expires_at)) {
            $inv->status = 'expired';
        }

        // 3 - paid - if confirmed amount is enough (even from expired)
        if (in_array($inv->status, ['pending', 'fixated', 'expired'], true) && $this->isPaid($inv, $receivedConf)) {
            $inv->status = 'paid';
            $inv->paid_at = $inv->paid_at ?? $now;

            if ($inv->paid_usd === null) {
                $rate = (float)$this->rates->usd($inv->coin);
                $paidUsd = $receivedConf * $rate;
                $inv->paid_usd = $paidUsd;

                $slip = $paidUsd - (float)$inv->expected_usd;

                $meta = is_array($inv->metadata) ? $inv->metadata : (array)($inv->metadata ?? []);
                $meta['slippage']['paid_usd'] = $slip;
                $meta['slippage']['paid_rate_usd'] = $rate;
                $inv->metadata = $meta;
            }
        }

        $inv->save();

        return $inv->fresh();
    }

    private function isPaid(Invoice $inv, float $receivedConf)
    {
        $pct = (float)config('payments.slippage.paid_coin_percent', 0.5);
        $expected = (float)$inv->amount_coin;

        // need >= expected * (1 - pct/100)
        $need = $expected * max(0.0, (1.0 - $pct / 100.0));

        // micro epsilon against float whitenoise
        return $receivedConf + 1e-12 >= $need;
    }

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

    private function firstSeenTime(array $txs): ?int
    {
        $first = $this->pickFirstTx($txs);
        if (!$first) return  null;
        return (int)$first['time'] ?? null;
    }
}
