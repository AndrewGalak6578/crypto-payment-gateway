<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Data\SettlementPolicyDecision;
use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Models\MerchantSettlementEntry;
use Brick\Math\BigDecimal;
use RuntimeException;

final readonly class MerchantSettlementLedger
{
    public function __construct(private SettlementDecimal $decimal) {}

    public function markForwardCompleted(
        Invoice $invoice,
        MerchantSettlementAttempt $attempt,
        string $amount,
    ): MerchantSettlementEntry {
        $idempotencyKey = $this->forwardAttemptKey($invoice->id, $attempt->attempt_uuid);
        $existing = MerchantSettlementEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (
                $existing->settlement_attempt_id !== $attempt->id
                || $existing->type !== MerchantSettlementEntry::TYPE_FORWARD_SENT
                || $existing->status !== MerchantSettlementEntry::STATUS_COMPLETED
                || BigDecimal::of((string) $existing->amount_coin)->compareTo(BigDecimal::of($amount)) !== 0
                || $existing->txid !== $attempt->txid
                || $existing->destination_wallet !== $attempt->destination_address
            ) {
                throw new RuntimeException(
                    "Settlement ledger key [{$idempotencyKey}] conflicts with an existing entry."
                );
            }

            return $existing;
        }

        return MerchantSettlementEntry::query()->create([
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'settlement_attempt_id' => $attempt->id,
            'asset_key' => $invoice->resolvedAssetKey(),
            'network_key' => $invoice->resolvedNetworkKey(),
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => $amount,
            'fee_coin' => $this->remainingInvoiceFee($invoice, (string) ($invoice->fee_coin ?? '0')),
            'amount_usd' => $this->usdAmount($invoice, $amount),
            'destination_wallet' => $attempt->destination_address,
            'txid' => $attempt->txid,
            'idempotency_key' => $idempotencyKey,
            'error_message' => null,
            'metadata' => [
                'attempt_uuid' => $attempt->attempt_uuid,
                'invoice_public_id' => $invoice->public_id,
            ],
            'occurred_at' => now('UTC'),
        ]);
    }

    public function recordInternalCredit(
        Invoice $invoice,
        string $amount,
        string $feeCoin,
        ?string $amountUsd,
        string $reason,
    ): MerchantSettlementEntry {
        return MerchantSettlementEntry::query()->firstOrCreate(
            ['idempotency_key' => "invoice:{$invoice->id}:internal-credit"],
            [
                'merchant_id' => $invoice->merchant_id,
                'invoice_id' => $invoice->id,
                'settlement_attempt_id' => null,
                'asset_key' => $invoice->resolvedAssetKey(),
                'network_key' => $invoice->resolvedNetworkKey(),
                'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
                'status' => MerchantSettlementEntry::STATUS_COMPLETED,
                'amount_coin' => $amount,
                'fee_coin' => $this->remainingInvoiceFee($invoice, $feeCoin),
                'amount_usd' => $amountUsd,
                'destination_wallet' => null,
                'txid' => null,
                'error_message' => null,
                'metadata' => [
                    'invoice_public_id' => $invoice->public_id,
                    'reason' => $reason,
                ],
                'occurred_at' => now('UTC'),
            ],
        );
    }

    public function completedForwardAmount(Invoice $invoice): string
    {
        return $this->completedAmount($invoice, MerchantSettlementEntry::TYPE_FORWARD_SENT);
    }

    public function completedInternalCreditAmount(Invoice $invoice): string
    {
        return $this->completedAmount($invoice, MerchantSettlementEntry::TYPE_INTERNAL_CREDIT);
    }

    public function recordPolicyHold(Invoice $invoice, SettlementPolicyDecision $decision): MerchantSettlementEntry
    {
        $idempotencyKey = "invoice:{$invoice->id}:settlement-policy-hold";
        $existing = MerchantSettlementEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing && (
            $existing->type !== MerchantSettlementEntry::TYPE_FORWARD_HELD
            || $existing->status === MerchantSettlementEntry::STATUS_COMPLETED
        )) {
            return $existing;
        }

        return MerchantSettlementEntry::query()->updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'merchant_id' => $invoice->merchant_id,
                'invoice_id' => $invoice->id,
                'settlement_attempt_id' => null,
                'asset_key' => $decision->assetKey,
                'network_key' => $decision->networkKey,
                'type' => MerchantSettlementEntry::TYPE_FORWARD_HELD,
                'status' => MerchantSettlementEntry::STATUS_DEFERRED,
                'amount_coin' => $decision->remainingAmount,
                'fee_coin' => $invoice->fee_coin,
                'amount_usd' => $this->usdAmount($invoice, $decision->remainingAmount),
                'destination_wallet' => null,
                'txid' => null,
                'error_message' => $decision->reason,
                'metadata' => [
                    'invoice_public_id' => $invoice->public_id,
                    'settlement_mode' => $decision->mode,
                    'reason' => $decision->reason,
                    'min_sweep_amount' => $decision->minSweepAmount,
                    'max_gas_cost' => $decision->maxGasCost,
                    'remaining_amount' => $decision->remainingAmount,
                    'forwarding_allowed' => $decision->forwardingAllowed,
                    'policy_snapshot' => $decision->policySnapshot,
                ],
                'occurred_at' => $existing?->occurred_at ?? now('UTC'),
            ],
        );
    }

    private function forwardAttemptKey(int $invoiceId, string $attemptUuid): string
    {
        return "invoice:{$invoiceId}:forward:{$attemptUuid}";
    }

    private function completedAmount(Invoice $invoice, string $type): string
    {
        $total = BigDecimal::zero();

        MerchantSettlementEntry::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', $type)
            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->pluck('amount_coin')
            ->each(function (mixed $amount) use (&$total): void {
                $total = $total->plus(BigDecimal::of((string) $amount));
            });

        return $this->decimal->format($total, $invoice->resolvedAssetKey());
    }

    private function usdAmount(Invoice $invoice, string $amount): ?string
    {
        $rate = BigDecimal::of((string) ($invoice->rate_usd ?? '0'));

        return $rate->compareTo(BigDecimal::zero()) > 0
            ? $this->decimal->usd($amount, $rate)
            : null;
    }

    private function remainingInvoiceFee(Invoice $invoice, string $invoiceFee): string
    {
        $recordedFee = BigDecimal::zero();

        MerchantSettlementEntry::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->whereNotNull('fee_coin')
            ->pluck('fee_coin')
            ->each(function (mixed $fee) use (&$recordedFee): void {
                $recordedFee = $recordedFee->plus(BigDecimal::of((string) $fee));
            });

        return (string) $this->decimal->positiveOrZero(
            BigDecimal::of($invoiceFee)->minus($recordedFee),
            $invoice->resolvedAssetKey(),
        );
    }
}
