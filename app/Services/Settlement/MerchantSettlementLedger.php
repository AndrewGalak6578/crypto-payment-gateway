<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Data\InternalCreditSourceResult;
use App\Data\SettlementPolicyDecision;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Models\MerchantSettlementEntry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
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
            'fee_coin' => $this->remainingInvoiceFee(
                $invoice,
                (string) ($invoice->getRawOriginal('fee_coin') ?? '0'),
            ),
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
        return $this->createOrAssertExactInternalCredit(
            $invoice,
            $amount,
            $feeCoin,
            $amountUsd,
            $reason,
        )->entry;
    }

    public function createOrAssertExactInternalCredit(
        Invoice $invoice,
        string $amount,
        string $feeCoin,
        ?string $amountUsd,
        string $reason,
    ): InternalCreditSourceResult {
        $assetKey = $invoice->resolvedAssetKey();
        $idempotencyKey = "invoice:{$invoice->id}:internal-credit";
        $amount = $this->decimal->formatExact($amount, $assetKey);
        $feeCoin = $this->remainingInvoiceFee($invoice, $feeCoin, $idempotencyKey);
        $amountUsd = $amountUsd === null ? null : $this->decimal->usdExact($amountUsd);
        $metadata = [
            'invoice_public_id' => (string) $invoice->public_id,
            'reason' => $reason,
        ];
        $now = now('UTC');

        $inserted = DB::table('merchant_settlement_entries')->insertOrIgnore([
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'settlement_attempt_id' => null,
            'asset_key' => $assetKey,
            'network_key' => $invoice->resolvedNetworkKey(),
            'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => $amount,
            'fee_coin' => $feeCoin,
            'amount_usd' => $amountUsd,
            'destination_wallet' => null,
            'txid' => null,
            'idempotency_key' => $idempotencyKey,
            'error_message' => null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var MerchantSettlementEntry|null $entry */
        $entry = MerchantSettlementEntry::query()
            ->where(function ($query) use ($invoice, $idempotencyKey): void {
                $query->where('idempotency_key', $idempotencyKey)
                    ->orWhere(function ($invoiceSource) use ($invoice): void {
                        $invoiceSource->where('invoice_id', $invoice->id)
                            ->where('type', MerchantSettlementEntry::TYPE_INTERNAL_CREDIT)
                            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED);
                    });
            })
            ->orderByRaw('CASE WHEN idempotency_key = ? THEN 0 ELSE 1 END', [$idempotencyKey])
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            throw new RuntimeException('Internal-credit source insertion did not return a durable row.');
        }

        $this->assertExactInternalCredit(
            entry: $entry,
            invoice: $invoice,
            amount: $amount,
            feeCoin: $feeCoin,
            amountUsd: $amountUsd,
            reason: $reason,
            metadata: $metadata,
            idempotencyKey: $idempotencyKey,
        );

        return new InternalCreditSourceResult($entry->fresh(), $inserted === 1);
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

    public function remainingInvoiceFee(
        Invoice $invoice,
        string $invoiceFee,
        ?string $excludedIdempotencyKey = null,
    ): string {
        $assetKey = $invoice->resolvedAssetKey();
        $invoiceFee = $this->decimal->assetExact($invoiceFee, $assetKey);
        $recordedFee = BigDecimal::zero();

        MerchantSettlementEntry::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
            ->whereNotNull('fee_coin')
            ->when(
                $excludedIdempotencyKey !== null,
                fn ($query) => $query->where('idempotency_key', '<>', $excludedIdempotencyKey),
            )
            ->orderBy('id')
            ->get(['id', 'fee_coin'])
            ->each(function (MerchantSettlementEntry $entry) use (&$recordedFee, $assetKey): void {
                $recordedFee = $recordedFee->plus($this->decimal->assetExact(
                    (string) $entry->getRawOriginal('fee_coin'),
                    $assetKey,
                ));
            });

        $remaining = $invoiceFee->minus($recordedFee);

        return $remaining->compareTo(BigDecimal::zero()) > 0
            ? (string) $this->decimal->assetExact((string) $remaining, $assetKey)
            : (string) $this->decimal->assetExact('0', $assetKey);
    }

    /**
     * @param  array{invoice_public_id: string, reason: string}  $metadata
     */
    private function assertExactInternalCredit(
        MerchantSettlementEntry $entry,
        Invoice $invoice,
        string $amount,
        string $feeCoin,
        ?string $amountUsd,
        string $reason,
        array $metadata,
        string $idempotencyKey,
    ): void {
        $assetKey = $invoice->resolvedAssetKey();
        $rawAmount = $entry->getRawOriginal('amount_coin');
        $rawFeeCoin = $entry->getRawOriginal('fee_coin');
        $rawAmountUsd = $entry->getRawOriginal('amount_usd');
        $actualAmount = $this->decimal->formatExact((string) $rawAmount, $assetKey);
        $actualFee = $rawFeeCoin === null
            ? null
            : $this->decimal->formatExact((string) $rawFeeCoin, $assetKey);
        $actualUsd = $rawAmountUsd === null
            ? null
            : $this->decimal->usdExact((string) $rawAmountUsd);

        if (
            (int) $entry->merchant_id !== (int) $invoice->merchant_id
            || (int) $entry->invoice_id !== (int) $invoice->id
            || $entry->settlement_attempt_id !== null
            || $entry->asset_key !== $assetKey
            || $entry->network_key !== $invoice->resolvedNetworkKey()
            || $entry->type !== MerchantSettlementEntry::TYPE_INTERNAL_CREDIT
            || $entry->status !== MerchantSettlementEntry::STATUS_COMPLETED
            || $actualAmount !== $amount
            || $actualFee !== $feeCoin
            || $actualUsd !== $amountUsd
            || $entry->destination_wallet !== null
            || $entry->txid !== null
            || $entry->idempotency_key !== $idempotencyKey
            || $entry->error_message !== null
            || $entry->metadata != $metadata
            || ($entry->metadata['reason'] ?? null) !== $reason
            || $entry->occurred_at === null
        ) {
            throw new CustodyIdempotencyConflictException(
                "Internal-credit source key [{$idempotencyKey}] conflicts with existing financial evidence."
            );
        }
    }
}
