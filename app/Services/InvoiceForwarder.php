<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\SuperWallet;
use App\Services\Settlement\MerchantBalanceCreditor;
use App\Services\Settlement\SuperWalletResolver;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use App\Support\Coin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class InvoiceForwarder
{
    public function __construct(
        private readonly EnqueueInvoiceWebhook $enqueueWebhook,
        private readonly SuperWalletResolver $walletResolver,
        private readonly MerchantBalanceCreditor $balanceCreditor,
    )
    {
    }

    public function forward(int $invoiceId): void
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()
            ->with('merchant')
            ->findOrFail($invoiceId);

        $wallet = $this->walletResolver->resolve($invoice->merchant, $invoice->coin);

        if (!$wallet) {
            $this->balanceCreditor->credit($invoiceId);

            $fresh = Invoice::query()->with('merchant')->find($invoiceId);
            if ($fresh) {
                $this->enqueueWebhook->enqueue('invoice.forwarded', $fresh);
            }

            return;
        }

        $plan = $this->reserveForwarding($invoiceId, $wallet);

        if ($plan === null) {
            return;
        }

        try {
            $rpc = Coin::rpc($plan['coin']);

            $txid = $rpc->sendToAddress(
                $plan['wallet'],
                $plan['amount'],
                $plan['fee_rate'],
            );

            $this->markForwarded(
                invoiceId: $invoiceId,
                attemptUuid: $plan['attempt_uuid'],
                amount: $plan['amount'],
                txid: $txid
            );

            $fresh = Invoice::query()->with('merchant')->find($invoiceId);

            if ($fresh) {
                $this->enqueueWebhook->enqueue('invoice.forwarded', $fresh);
            }
        } catch (Throwable $e) {
            $this->markFailed($invoiceId, $plan['attempt_uuid']);
            report($e);
            throw $e;
        }
    }

    private function reserveForwarding(int $invoiceId, SuperWallet $wallet): ?array
    {
        return DB::transaction(function () use ($invoiceId, $wallet): ?array {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->status !== 'paid') {
                return null;
            }

            if ($invoice->forward_attempt_uuid !== null) {
                return null;
            }

            $scale = $this->scale($invoice->coin);
            $epsilon = $this->epsilon($invoice->coin);

            $confirmed = $this->norm((float) ($invoice->received_conf_coin ?? 0), $scale);
            $forwarded = $this->norm((float) ($invoice->forwarded_coin ?? 0), $scale);
            $feePercent = (float) ($invoice->merchant->fee_percent ?? 0.0);
            $targetNet = $this->norm($confirmed - ($confirmed * ($feePercent / 100)), $scale);
            $targetNet = max(0.0, $targetNet);
            $amount = $this->norm($targetNet - $forwarded, $scale);
            $amount = max(0.0, $amount);

            if ($amount <= $epsilon) {
                $invoice->forward_status = 'done';
                $invoice->save();

                return null;
            }

            $min = $this->norm((float) config("forwarding.min_coin.{$invoice->coin}", 0), $scale);

            if ($amount < $min) {
                $invoice->forward_status = $forwarded > $epsilon ? 'partial' : 'none';
                $invoice->save();

                return null;
            }

            $attemptUuid = (string) Str::uuid();

            $invoice->forward_status = 'processing';
            $invoice->forward_attempt_uuid = $attemptUuid;
            $invoice->forwarding_coin = $amount;
            $invoice->forwarding_started_at = now('UTC');
            $invoice->save();

            return [
                'attempt_uuid' => $attemptUuid,
                'coin' => $invoice->coin,
                'wallet' => $wallet->wallet,
                'fee_rate' => $wallet->fee_rate !== null ? (float) $wallet->fee_rate : null,
                'amount' => $amount,
            ];
        });
    }

    private function markForwarded(int $invoiceId, string $attemptUuid, float $amount, string $txid): void
    {
        DB::transaction(function () use ($invoiceId, $attemptUuid, $amount, $txid): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->forward_attempt_uuid !== $attemptUuid) {
                return;
            }

            $scale = $this->scale($invoice->coin);
            $epsilon = $this->epsilon($invoice->coin);

            $txids = $invoice->forward_txids ?? [];
            $txids[] = $txid;

            $newForwarded = $this->norm(
                (float) ($invoice->forwarded_coin ?? 0) + $amount,
                $scale
            );

            $confirmed = $this->norm((float) ($invoice->received_conf_coin ?? 0), $scale);
            $feePercent = (float) ($invoice->merchant->fee_percent ?? 0.0);
            $targetNet = $this->norm($confirmed - ($confirmed * ($feePercent / 100)), $scale);
            $targetNet = max(0.0, $targetNet);
            $rest = $this->norm($targetNet - $newForwarded, $scale);

            $invoice->forwarded_coin = $newForwarded;
            $invoice->forward_txids = $txids;
            $invoice->last_forwarded_at = now('UTC');
            $invoice->forward_status = $rest <= $epsilon ? 'done' : 'partial';

            $invoice->forward_attempt_uuid = null;
            $invoice->forwarding_coin = null;
            $invoice->forwarding_started_at = null;

            $invoice->save();
        });
    }
    private function markFailed(int $invoiceId, string $attemptUuid): void
    {
        DB::transaction(function () use ($invoiceId, $attemptUuid): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->forward_attempt_uuid !== $attemptUuid) {
                return;
            }

            $invoice->forward_status = 'failed';
            $invoice->forward_attempt_uuid = null;
            $invoice->forwarding_coin = null;
            $invoice->forwarding_started_at = null;
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

    /**
     *
     */
    private function norm(float $value, int $scale): float
    {
        return round($value, $scale);
    }

    private function epsilon(string $coin): float
    {
        return match ($coin) {
            'dash' => 0.001,
            'btc', 'ltc' => 0.00000001,
            default => 0.00000001,
        };
    }
}
