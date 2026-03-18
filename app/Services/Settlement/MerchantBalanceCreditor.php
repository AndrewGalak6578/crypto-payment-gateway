<?php
declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\Invoice;
use App\Models\MerchantBalance;
use Illuminate\Support\Facades\DB;

final class MerchantBalanceCreditor
{
    public function credit(int $invoiceId): void
    {
        DB::transaction(function () use ($invoiceId): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->with('merchant')
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->status !== 'paid') {
                return;
            }

            if ($invoice->merchant_payout_coin !== null) {
                return;
            }

            $scale = $this->scale($invoice->coin);

            $grossCoin = $this->norm((float)($invoice->received_conf_coin ?? 0), $scale);
            $feePercent = (float)($invoice->merchant->fee_percent ?? 0.0);

            $feeCoin = $this->norm($grossCoin * ($feePercent / 100), $scale);
            $payoutCoin = $this->norm($grossCoin - $feeCoin, $scale);

            $rateUsd = (float)($invoice->rate_usd ?? 0.0);
            $feeUsd = round($feeCoin * $rateUsd, 2);
            $payoutUsd = round($payoutCoin * $rateUsd, 2);

            /** @var MerchantBalance $balance */
            $balance = MerchantBalance::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    [
                        'merchant_id' => $invoice->merchant_id,
                        'coin' => $invoice->coin,
                    ],
                    [
                        'amount' => 0
                    ]
                );

            $balance->amount = $this->norm((float) $balance->amount + $payoutCoin, $scale);
            $balance->save();

            $invoice->fee_coin = $feeCoin;
            $invoice->merchant_payout_coin = $payoutCoin;
            $invoice->fee_usd = $feeUsd;
            $invoice->merchant_payout_usd = $payoutUsd;
            $invoice->forward_status = 'done';
            $invoice->save();
        });
    }

    private function scale(string $coin): int
    {
        return match ($coin) {
            'dash' => 3,
            default => 8,
        };
    }

    private function norm(float $value, int $scale): float
    {
        return round($value, $scale);
    }
}
