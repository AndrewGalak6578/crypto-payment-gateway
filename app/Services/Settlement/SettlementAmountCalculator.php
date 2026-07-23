<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\Invoice;
use Brick\Math\BigDecimal;

final readonly class SettlementAmountCalculator
{
    public function __construct(
        private MerchantSettlementLedger $ledger,
        private SettlementDecimal $decimal,
    ) {}

    public function targetNetCoin(Invoice $invoice): string
    {
        $assetKey = $invoice->resolvedAssetKey();
        $gross = $this->decimal->asset($invoice->received_conf_coin, $assetKey);

        if ($invoice->merchant_payout_coin !== null) {
            return (string) $this->decimal->positiveOrZero(
                $this->decimal->asset($invoice->merchant_payout_coin, $assetKey),
                $assetKey,
            );
        }

        if ($invoice->fee_coin !== null) {
            return (string) $this->decimal->positiveOrZero(
                $gross->minus($this->decimal->asset($invoice->fee_coin, $assetKey)),
                $assetKey,
            );
        }

        $fee = $this->decimal->percentage(
            $gross,
            $invoice->merchant->getRawOriginal('fee_percent') ?? '0',
            $assetKey,
        );

        return (string) $this->decimal->positiveOrZero($gross->minus($fee), $assetKey);
    }

    public function recordedForwardedCoin(Invoice $invoice): string
    {
        $assetKey = $invoice->resolvedAssetKey();
        $invoiceValue = $this->decimal->asset($invoice->forwarded_coin, $assetKey);
        $ledgerValue = $this->decimal->asset($this->ledger->completedForwardAmount($invoice), $assetKey);

        return (string) ($invoiceValue->compareTo($ledgerValue) >= 0 ? $invoiceValue : $ledgerValue);
    }

    public function hasCompletedInternalCredit(Invoice $invoice): bool
    {
        return BigDecimal::of($this->ledger->completedInternalCreditAmount($invoice))
            ->compareTo(BigDecimal::zero()) > 0;
    }

    public function remainingPayoutCoin(Invoice $invoice): string
    {
        $assetKey = $invoice->resolvedAssetKey();

        if ($this->hasCompletedInternalCredit($invoice)) {
            return (string) $this->decimal->zero($assetKey);
        }

        return (string) $this->decimal->positiveOrZero(
            $this->decimal->asset($this->targetNetCoin($invoice), $assetKey)
                ->minus($this->decimal->asset($this->recordedForwardedCoin($invoice), $assetKey)),
            $assetKey,
        );
    }
}
