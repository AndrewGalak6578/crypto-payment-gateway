<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\Invoice;
use App\Models\MerchantBalance;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class MerchantBalanceCreditor
{
    public function __construct(
        private MerchantSettlementLedger $settlementLedger,
        private SettlementAmountCalculator $amounts,
        private SettlementDecimal $decimal,
        private EnqueueInvoiceWebhook $enqueueWebhook,
    ) {}

    public function credit(int $invoiceId, string $reason): void
    {
        DB::transaction(function () use ($invoiceId, $reason): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->with('merchant')
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            if ($invoice->status !== 'paid' || ! $invoice->hasRetryableForwardStatus()) {
                return;
            }

            if (
                $invoice->settlement_snapshot_locked_at === null
                || $invoice->fee_coin === null
                || $invoice->merchant_payout_coin === null
            ) {
                throw new RuntimeException(
                    "Invoice [{$invoice->id}] must have a complete locked settlement snapshot before internal credit."
                );
            }

            $assetKey = $invoice->resolvedAssetKey();
            $feeCoin = $this->decimal->format($invoice->fee_coin, $assetKey);
            $forwardedCoin = $this->amounts->recordedForwardedCoin($invoice);
            $remainingCoin = $this->amounts->remainingPayoutCoin($invoice);
            $invoice->forwarded_coin = $forwardedCoin;

            if (BigDecimal::of($remainingCoin)->isZero()) {
                $invoice->forward_status = Invoice::FORWARD_STATUS_DONE;
                $invoice->save();
                $this->enqueueForwardedWebhook($invoice);

                return;
            }

            /** @var MerchantBalance $balance */
            $balance = MerchantBalance::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['merchant_id' => $invoice->merchant_id, 'coin' => $assetKey],
                    ['amount' => '0'],
                );

            $balance->amount = (string) $this->decimal->asset($balance->amount, $assetKey)
                ->plus($this->decimal->asset($remainingCoin, $assetKey));
            $balance->save();

            $invoice->forward_status = Invoice::FORWARD_STATUS_DONE;
            $invoice->save();

            $rate = BigDecimal::of((string) ($invoice->rate_usd ?? '0'));
            $this->settlementLedger->recordInternalCredit(
                invoice: $invoice,
                amount: $remainingCoin,
                feeCoin: $feeCoin,
                amountUsd: $rate->compareTo(BigDecimal::zero()) > 0
                    ? $this->decimal->usd($remainingCoin, $rate)
                    : null,
                reason: $reason,
            );
            $this->enqueueForwardedWebhook($invoice);
        });
    }

    private function enqueueForwardedWebhook(Invoice $invoice): void
    {
        $this->enqueueWebhook->enqueue(
            'invoice.forwarded',
            $invoice,
            "invoice:{$invoice->id}:event:invoice.forwarded",
        );
    }
}
