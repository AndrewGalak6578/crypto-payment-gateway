<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Exceptions\CustodyAccountingException;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementEntry;
use App\Services\Custody\Phase2AGate;
use App\Services\Custody\Phase2AInternalCreditProjector;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class MerchantBalanceCreditor
{
    public function __construct(
        private MerchantSettlementLedger $settlementLedger,
        private SettlementDecimal $decimal,
        private EnqueueInvoiceWebhook $enqueueWebhook,
        private Phase2AGate $phase2aGate,
        private Phase2AInternalCreditProjector $phase2aProjector,
    ) {}

    public function credit(int $invoiceId, string $reason): void
    {
        DB::transaction(function () use ($invoiceId, $reason): void {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            /** @var Merchant $merchant */
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($invoice->merchant_id);
            $invoice->setRelation('merchant', $merchant);

            $this->creditLocked($invoice, $reason);
        });
    }

    public function creditLocked(Invoice $invoice, string $reason): void
    {
        if (DB::transactionLevel() < 1) {
            throw new CustodyAccountingException(
                'Locked internal credit requires the InvoiceForwarder outer transaction.',
            );
        }

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
        $invoiceFeeCoin = $this->decimal->formatExact(
            (string) $invoice->getRawOriginal('fee_coin'),
            $assetKey,
        );
        $forwardedCoin = $this->exactRecordedForwardedCoin($invoice, $assetKey);
        $targetCoin = $this->decimal->assetExact(
            (string) $invoice->getRawOriginal('merchant_payout_coin'),
            $assetKey,
        );
        $remaining = $targetCoin->minus($this->decimal->assetExact($forwardedCoin, $assetKey));
        $remainingCoin = $remaining->compareTo(BigDecimal::zero()) > 0
            ? (string) $this->decimal->assetExact((string) $remaining, $assetKey)
            : (string) $this->decimal->assetExact('0', $assetKey);
        $this->settlementLedger->remainingInvoiceFee(
            $invoice,
            $invoiceFeeCoin,
            "invoice:{$invoice->id}:internal-credit",
        );
        $invoice->forwarded_coin = $forwardedCoin;

        if (BigDecimal::of($remainingCoin)->isZero()) {
            $invoice->forward_status = Invoice::FORWARD_STATUS_DONE;
            $invoice->save();
            $this->enqueueForwardedWebhook($invoice);

            return;
        }

        $shadowRequired = $this->phase2aGate->shadowRequiredForPositiveCredit();

        /** @var MerchantBalance $balance */
        $balance = MerchantBalance::query()
            ->lockForUpdate()
            ->firstOrCreate(
                ['merchant_id' => $invoice->merchant_id, 'coin' => $assetKey],
                ['amount' => '0'],
            );

        $this->assertNoPreExistingSource($invoice);

        $rate = BigDecimal::of((string) ($invoice->rate_usd ?? '0'));
        $amountUsd = $rate->compareTo(BigDecimal::zero()) > 0
            ? $this->decimal->usd($remainingCoin, $rate)
            : null;

        if ($shadowRequired) {
            $sourceResult = $this->settlementLedger->createOrAssertExactInternalCredit(
                invoice: $invoice,
                amount: $remainingCoin,
                feeCoin: $invoiceFeeCoin,
                amountUsd: $amountUsd,
                reason: $reason,
            );

            if (! $sourceResult->createdInCurrentTransaction) {
                throw new CustodyIdempotencyConflictException(
                    "Retryable invoice [{$invoice->id}] has a pre-existing internal-credit source.",
                );
            }

            $this->phase2aProjector->projectCreatedSource($sourceResult->entry, $balance);
        }

        $balance->amount = (string) $this->decimal->assetExact((string) $balance->amount, $assetKey)
            ->plus($this->decimal->assetExact($remainingCoin, $assetKey));
        $balance->save();

        $invoice->forward_status = Invoice::FORWARD_STATUS_DONE;
        $invoice->save();

        if (! $shadowRequired) {
            $this->settlementLedger->recordInternalCredit(
                invoice: $invoice,
                amount: $remainingCoin,
                feeCoin: $invoiceFeeCoin,
                amountUsd: $amountUsd,
                reason: $reason,
            );
        }

        $this->enqueueForwardedWebhook($invoice);
    }

    private function enqueueForwardedWebhook(Invoice $invoice): void
    {
        $this->enqueueWebhook->enqueue(
            'invoice.forwarded',
            $invoice,
            "invoice:{$invoice->id}:event:invoice.forwarded",
        );
    }

    private function assertNoPreExistingSource(Invoice $invoice): void
    {
        $existing = MerchantSettlementEntry::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', MerchantSettlementEntry::TYPE_INTERNAL_CREDIT)
            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw new CustodyIdempotencyConflictException(
                "Retryable invoice [{$invoice->id}] has pre-existing internal-credit financial evidence.",
            );
        }
    }

    private function exactRecordedForwardedCoin(Invoice $invoice, string $assetKey): string
    {
        $rawInvoiceAmount = $invoice->getRawOriginal('forwarded_coin');
        $invoiceAmount = $this->decimal->assetExact(
            $rawInvoiceAmount === null ? '0' : (string) $rawInvoiceAmount,
            $assetKey,
        );
        $ledgerAmount = BigDecimal::zero();

        MerchantSettlementEntry::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', MerchantSettlementEntry::TYPE_FORWARD_SENT)
            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->orderBy('id')
            ->get(['id', 'amount_coin'])
            ->each(function (MerchantSettlementEntry $entry) use (&$ledgerAmount, $assetKey): void {
                $ledgerAmount = $ledgerAmount->plus($this->decimal->assetExact(
                    (string) $entry->getRawOriginal('amount_coin'),
                    $assetKey,
                ));
            });

        $ledgerAmount = $this->decimal->assetExact((string) $ledgerAmount, $assetKey);

        return (string) ($invoiceAmount->compareTo($ledgerAmount) >= 0 ? $invoiceAmount : $ledgerAmount);
    }
}
