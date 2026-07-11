<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\Invoice;
use App\Models\MerchantSettlementEntry;

final class MerchantSettlementLedger
{
    public function recordForwardPending(Invoice $invoice, string $attemptUuid, float $amount, string $destinationWallet): MerchantSettlementEntry
    {
        return MerchantSettlementEntry::query()->updateOrCreate(
            ['idempotency_key' => $this->forwardAttemptKey($invoice->id, $attemptUuid)],
            [
                'merchant_id' => $invoice->merchant_id,
                'invoice_id' => $invoice->id,
                'asset_key' => $invoice->resolvedAssetKey(),
                'network_key' => $invoice->resolvedNetworkKey(),
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => MerchantSettlementEntry::STATUS_PENDING,
                'amount_coin' => $amount,
                'fee_coin' => $invoice->fee_coin,
                'amount_usd' => $this->usdAmount($invoice, $amount),
                'destination_wallet' => $destinationWallet,
                'txid' => null,
                'error_message' => null,
                'metadata' => [
                    'attempt_uuid' => $attemptUuid,
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => now('UTC'),
            ],
        );
    }

    public function markForwardCompleted(Invoice $invoice, string $attemptUuid, float $amount, string $txid): void
    {
        MerchantSettlementEntry::query()->updateOrCreate(
            ['idempotency_key' => $this->forwardAttemptKey($invoice->id, $attemptUuid)],
            [
                'merchant_id' => $invoice->merchant_id,
                'invoice_id' => $invoice->id,
                'asset_key' => $invoice->resolvedAssetKey(),
                'network_key' => $invoice->resolvedNetworkKey(),
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => MerchantSettlementEntry::STATUS_COMPLETED,
                'amount_coin' => $amount,
                'fee_coin' => $invoice->fee_coin,
                'amount_usd' => $this->usdAmount($invoice, $amount),
                'destination_wallet' => $this->destinationWalletFromPending($invoice->id, $attemptUuid),
                'txid' => $txid,
                'error_message' => null,
                'metadata' => [
                    'attempt_uuid' => $attemptUuid,
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => now('UTC'),
            ],
        );
    }

    public function markForwardFailed(Invoice $invoice, string $attemptUuid, ?string $errorMessage = null): void
    {
        MerchantSettlementEntry::query()->updateOrCreate(
            ['idempotency_key' => $this->forwardAttemptKey($invoice->id, $attemptUuid)],
            [
                'merchant_id' => $invoice->merchant_id,
                'invoice_id' => $invoice->id,
                'asset_key' => $invoice->resolvedAssetKey(),
                'network_key' => $invoice->resolvedNetworkKey(),
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => MerchantSettlementEntry::STATUS_FAILED,
                'amount_coin' => $invoice->forwarding_coin ?? 0,
                'fee_coin' => $invoice->fee_coin,
                'amount_usd' => $this->usdAmount($invoice, (float) ($invoice->forwarding_coin ?? 0)),
                'destination_wallet' => $this->destinationWalletFromPending($invoice->id, $attemptUuid),
                'txid' => null,
                'error_message' => $errorMessage,
                'metadata' => [
                    'attempt_uuid' => $attemptUuid,
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => now('UTC'),
            ],
        );
    }

    public function markForwardDeferred(Invoice $invoice, string $attemptUuid, ?string $reason = null): void
    {
        MerchantSettlementEntry::query()->updateOrCreate(
            ['idempotency_key' => $this->forwardAttemptKey($invoice->id, $attemptUuid)],
            [
                'merchant_id' => $invoice->merchant_id,
                'invoice_id' => $invoice->id,
                'asset_key' => $invoice->resolvedAssetKey(),
                'network_key' => $invoice->resolvedNetworkKey(),
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => MerchantSettlementEntry::STATUS_DEFERRED,
                'amount_coin' => $invoice->forwarding_coin ?? 0,
                'fee_coin' => $invoice->fee_coin,
                'amount_usd' => $this->usdAmount($invoice, (float) ($invoice->forwarding_coin ?? 0)),
                'destination_wallet' => $this->destinationWalletFromPending($invoice->id, $attemptUuid),
                'txid' => null,
                'error_message' => $reason,
                'metadata' => [
                    'attempt_uuid' => $attemptUuid,
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => now('UTC'),
            ],
        );
    }

    public function recordInternalCredit(Invoice $invoice, float $amount, float $feeCoin, ?float $amountUsd = null): MerchantSettlementEntry
    {
        return MerchantSettlementEntry::query()->updateOrCreate(
            ['idempotency_key' => "invoice:{$invoice->id}:internal-credit"],
            [
                'merchant_id' => $invoice->merchant_id,
                'invoice_id' => $invoice->id,
                'asset_key' => $invoice->resolvedAssetKey(),
                'network_key' => $invoice->resolvedNetworkKey(),
                'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
                'status' => MerchantSettlementEntry::STATUS_COMPLETED,
                'amount_coin' => $amount,
                'fee_coin' => $feeCoin,
                'amount_usd' => $amountUsd,
                'destination_wallet' => null,
                'txid' => null,
                'error_message' => null,
                'metadata' => [
                    'invoice_public_id' => $invoice->public_id,
                    'reason' => 'destination_wallet_missing',
                ],
                'occurred_at' => now('UTC'),
            ],
        );
    }

    private function forwardAttemptKey(int $invoiceId, string $attemptUuid): string
    {
        return "invoice:{$invoiceId}:forward:{$attemptUuid}";
    }

    private function destinationWalletFromPending(int $invoiceId, string $attemptUuid): ?string
    {
        return MerchantSettlementEntry::query()
            ->where('idempotency_key', $this->forwardAttemptKey($invoiceId, $attemptUuid))
            ->value('destination_wallet');
    }

    private function usdAmount(Invoice $invoice, float $amount): ?float
    {
        $rate = (float) ($invoice->rate_usd ?? 0);
        if ($rate <= 0) {
            return null;
        }

        return round($amount * $rate, 2);
    }
}
