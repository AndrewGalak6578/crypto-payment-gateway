<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Data\SettlementCompletionResult;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSettlementAttempt;
use App\Services\Forwarding\ForwardingGate;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use App\Support\Chains\ChainRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class MerchantSettlementAttemptManager
{
    public function __construct(
        private SettlementAmountCalculator $amounts,
        private MerchantSettlementLedger $ledger,
        private SettlementDecimal $decimal,
        private ChainRegistry $chains,
        private EnqueueInvoiceWebhook $enqueueWebhook,
        private ForwardingGate $forwardingGate,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function reserve(
        int $invoiceId,
        string $chainFamily,
        string $transferType,
        string $destinationAddress,
        array $metadata = [],
        ?string $ownerToken = null,
    ): ?MerchantSettlementAttempt {
        return DB::transaction(function () use (
            $invoiceId,
            $chainFamily,
            $transferType,
            $destinationAddress,
            $metadata,
            $ownerToken,
        ): ?MerchantSettlementAttempt {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            /** @var Merchant $merchant */
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($invoice->merchant_id);
            $invoice->setRelation('merchant', $merchant);

            return $this->reserveLocked(
                invoice: $invoice,
                chainFamily: $chainFamily,
                transferType: $transferType,
                destinationAddress: $destinationAddress,
                metadata: $metadata,
                ownerToken: $ownerToken,
            );
        });
    }

    /**
     * Reserves against an invoice already locked by the caller's transaction.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function reserveLocked(
        Invoice $invoice,
        string $chainFamily,
        string $transferType,
        string $destinationAddress,
        array $metadata = [],
        ?string $ownerToken = null,
    ): ?MerchantSettlementAttempt {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('reserveLocked requires an active database transaction.');
        }

        if ($invoice->status !== 'paid' || ! $invoice->hasRetryableForwardStatus()) {
            return null;
        }

        $blockingAttempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('state', [
                MerchantSettlementAttempt::STATE_RESERVED,
                MerchantSettlementAttempt::STATE_BROADCASTING,
                MerchantSettlementAttempt::STATE_BROADCASTED,
                MerchantSettlementAttempt::STATE_CONFIRMED,
                MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
            ])
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($blockingAttempt !== null) {
            $invoice->forward_attempt_uuid = $blockingAttempt->attempt_uuid;
            $invoice->forward_status = $blockingAttempt->state === MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION
                ? Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION
                : Invoice::FORWARD_STATUS_PROCESSING;
            $invoice->save();

            return null;
        }

        if ($invoice->forward_attempt_uuid !== null) {
            $invoice->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
            $invoice->save();

            return null;
        }

        if (
            $invoice->settlement_snapshot_locked_at === null
            || $invoice->fee_coin === null
            || $invoice->merchant_payout_coin === null
        ) {
            throw new RuntimeException("Invoice [{$invoice->id}] has no complete locked settlement snapshot.");
        }

        $assetKey = $invoice->resolvedAssetKey();
        $recordedForwarded = $this->amounts->recordedForwardedCoin($invoice);
        if (
            $this->decimal->asset($recordedForwarded, $assetKey)
                ->compareTo($this->decimal->asset($invoice->forwarded_coin, $assetKey)) > 0
        ) {
            $invoice->forwarded_coin = $recordedForwarded;
        }

        $amount = $this->amounts->remainingPayoutCoin($invoice);
        if (BigDecimal::of($amount)->isZero()) {
            $invoice->forward_status = Invoice::FORWARD_STATUS_DONE;
            $invoice->save();

            return null;
        }

        $gateState = $this->forwardingGate->inspectWithSharedLock();
        $this->forwardingGate->throwIfOperationalFailure($gateState);

        if (! $gateState->effective()) {
            return null;
        }

        $attemptUuid = (string) Str::uuid();
        $now = now('UTC');
        $leaseSeconds = max(30, (int) config('forwarding.attempts.reservation_lease_seconds', 300));

        /** @var MerchantSettlementAttempt $attempt */
        $attempt = MerchantSettlementAttempt::query()->create([
            'attempt_uuid' => $attemptUuid,
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'asset_key' => $assetKey,
            'network_key' => $invoice->resolvedNetworkKey(),
            'chain_family' => $chainFamily,
            'transfer_type' => $transferType,
            'state' => MerchantSettlementAttempt::STATE_RESERVED,
            'retry_safe' => false,
            'amount_coin' => $amount,
            'fee_coin_snapshot' => $this->decimal->format($invoice->fee_coin, $assetKey),
            'merchant_payout_coin_snapshot' => $this->decimal->format($invoice->merchant_payout_coin, $assetKey),
            'destination_address' => $destinationAddress,
            'required_confirmations' => max(1, $this->chains->confirmations($invoice->resolvedNetworkKey())),
            'broadcast_reference' => "settlement:{$attemptUuid}",
            'metadata' => array_merge($metadata, ['invoice_public_id' => $invoice->public_id]),
            'lease_owner_token' => $ownerToken ?? (string) Str::uuid(),
            'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
            'heartbeat_at' => $now,
            'reserved_at' => $now,
        ]);

        $invoice->forward_status = Invoice::FORWARD_STATUS_PROCESSING;
        $invoice->forward_attempt_uuid = $attemptUuid;
        $invoice->forwarding_coin = $amount;
        $invoice->forwarding_started_at = $now;
        $invoice->save();

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordPreparationContext(
        int $attemptId,
        ?string $sourceAddress = null,
        ?string $sourceReference = null,
        int|string|null $nonce = null,
        ?string $chainId = null,
        ?int $broadcastBlockNumber = null,
        ?string $preparedAmount = null,
        ?string $atomicAmount = null,
        ?string $tokenContract = null,
        ?string $calldata = null,
        ?string $transactionFingerprint = null,
        array $metadata = [],
        ?string $ownerToken = null,
    ): MerchantSettlementAttempt {
        return DB::transaction(function () use (
            $attemptId,
            $sourceAddress,
            $sourceReference,
            $nonce,
            $chainId,
            $broadcastBlockNumber,
            $preparedAmount,
            $atomicAmount,
            $tokenContract,
            $calldata,
            $transactionFingerprint,
            $metadata,
            $ownerToken,
        ): MerchantSettlementAttempt {
            $attempt = $this->lockAttempt($attemptId);
            $this->assertReservedOwner($attempt, $ownerToken);

            $attempt->source_address = $sourceAddress;
            $attempt->source_reference = $sourceReference;
            $attempt->nonce = $nonce !== null ? (string) $nonce : null;
            $attempt->chain_id = $chainId;
            $attempt->broadcast_block_number = $broadcastBlockNumber;
            $attempt->prepared_amount_coin = $preparedAmount !== null
                ? $this->decimal->format($preparedAmount, $attempt->asset_key)
                : $attempt->prepared_amount_coin;
            $attempt->atomic_amount = $atomicAmount;
            $attempt->token_contract = $tokenContract;
            $attempt->calldata = $calldata;
            $attempt->calldata_fingerprint = $calldata !== null ? hash('sha256', strtolower($calldata)) : null;
            $attempt->transaction_fingerprint = $transactionFingerprint;
            $attempt->metadata = array_merge($attempt->metadata ?? [], $metadata);
            $this->refreshLease($attempt);
            $attempt->save();

            return $attempt;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markBroadcasting(
        int $attemptId,
        ?string $sourceAddress = null,
        ?string $sourceReference = null,
        int|string|null $nonce = null,
        ?string $tokenContract = null,
        ?string $transactionFingerprint = null,
        array $metadata = [],
        ?string $ownerToken = null,
    ): MerchantSettlementAttempt {
        [$attempt, $failure] = DB::transaction(function () use (
            $attemptId,
            $sourceAddress,
            $sourceReference,
            $nonce,
            $tokenContract,
            $transactionFingerprint,
            $metadata,
            $ownerToken,
        ): array {
            [$attempt, $invoice] = $this->lockSettlementContext($attemptId);

            if ($attempt->state === MerchantSettlementAttempt::STATE_BROADCASTING) {
                throw new RuntimeException(
                    "Settlement attempt [{$attempt->attempt_uuid}] already crossed the broadcast ambiguity boundary."
                );
            }

            $this->assertReservedOwner($attempt, $ownerToken);
            $gateState = $this->forwardingGate->inspectWithSharedLock();

            if (! $gateState->configValid) {
                $this->failRetrySafeLocked($attempt, $invoice, 'forwarding_configuration_invalid_before_broadcast');

                return [$attempt, 'configuration_invalid'];
            }

            if (! $gateState->dbAvailable) {
                throw new \App\Exceptions\ForwardingSwitchUnavailableException(
                    'forwarding_switch_unavailable_before_broadcast',
                );
            }

            if (! $gateState->effective()) {
                $this->failRetrySafeLocked($attempt, $invoice, 'forwarding_disabled_before_broadcast');

                return [$attempt, null];
            }

            $attempt->state = MerchantSettlementAttempt::STATE_BROADCASTING;
            $attempt->retry_safe = false;
            $attempt->source_address = $sourceAddress ?? $attempt->source_address;
            $attempt->source_reference = $sourceReference ?? $attempt->source_reference;
            $attempt->nonce = $nonce !== null ? (string) $nonce : $attempt->nonce;
            $attempt->token_contract = $tokenContract ?? $attempt->token_contract;
            $attempt->transaction_fingerprint = $transactionFingerprint ?? $attempt->transaction_fingerprint;
            $attempt->metadata = array_merge($attempt->metadata ?? [], $metadata);
            $attempt->broadcasting_at = now('UTC');
            $attempt->lease_expires_at = null;
            $attempt->save();

            return [$attempt, null];
        });

        if ($failure === 'configuration_invalid') {
            throw new \App\Exceptions\ForwardingConfigurationException($attempt->error_message);
        }

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markBroadcasted(
        int $attemptId,
        string $txid,
        string $broadcastAmount,
        int|string|null $nonce = null,
        array $metadata = [],
    ): MerchantSettlementAttempt {
        return DB::transaction(function () use ($attemptId, $txid, $broadcastAmount, $nonce, $metadata): MerchantSettlementAttempt {
            $attempt = $this->lockAttempt($attemptId);

            if ($attempt->state === MerchantSettlementAttempt::STATE_BROADCASTED) {
                if ($attempt->txid !== $txid) {
                    throw new RuntimeException("Settlement attempt [{$attempt->attempt_uuid}] already has a different txid.");
                }

                return $attempt;
            }

            $this->assertState($attempt, MerchantSettlementAttempt::STATE_BROADCASTING);
            $attempt->state = MerchantSettlementAttempt::STATE_BROADCASTED;
            $attempt->txid = $txid;
            $normalizedAmount = $this->decimal->format($broadcastAmount, $attempt->asset_key);
            if (
                $attempt->prepared_amount_coin !== null
                && BigDecimal::of($normalizedAmount)->compareTo(BigDecimal::of((string) $attempt->prepared_amount_coin)) !== 0
            ) {
                throw new RuntimeException(
                    "Settlement attempt [{$attempt->attempt_uuid}] broadcast amount differs from its prepared payload."
                );
            }
            $attempt->broadcast_amount_coin = $normalizedAmount;
            $attempt->nonce = $nonce !== null ? (string) $nonce : $attempt->nonce;
            $attempt->metadata = array_merge($attempt->metadata ?? [], $metadata);
            $attempt->broadcasted_at = now('UTC');
            $attempt->next_reconciliation_at = now('UTC');
            $attempt->save();

            return $attempt;
        });
    }

    /**
     * Records a txid recovered by chain evidence without treating it as confirmed.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function recordRecoveredBroadcast(int $attemptId, string $txid, array $evidence = []): MerchantSettlementAttempt
    {
        return DB::transaction(function () use ($attemptId, $txid, $evidence): MerchantSettlementAttempt {
            [$attempt, $invoice] = $this->lockSettlementContext($attemptId);

            if ($attempt->txid !== null && $attempt->txid !== $txid) {
                throw new RuntimeException("Settlement attempt [{$attempt->attempt_uuid}] has conflicting recovered txids.");
            }

            if (! in_array($attempt->state, [
                MerchantSettlementAttempt::STATE_BROADCASTING,
                MerchantSettlementAttempt::STATE_BROADCASTED,
                MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
            ], true)) {
                return $attempt;
            }

            $attempt->state = MerchantSettlementAttempt::STATE_BROADCASTED;
            $attempt->txid = $txid;
            $attempt->broadcast_amount_coin ??= $attempt->prepared_amount_coin ?? $attempt->amount_coin;
            $attempt->broadcasted_at ??= now('UTC');
            $attempt->metadata = array_merge($attempt->metadata ?? [], ['recovered_broadcast' => $evidence]);
            $attempt->save();

            $this->markInvoiceProcessing($attempt, $invoice);

            return $attempt;
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function markConfirmed(int $attemptId, array $evidence = []): MerchantSettlementAttempt
    {
        return DB::transaction(function () use ($attemptId, $evidence): MerchantSettlementAttempt {
            $attempt = $this->lockAttempt($attemptId);

            if (in_array($attempt->state, [
                MerchantSettlementAttempt::STATE_CONFIRMED,
                MerchantSettlementAttempt::STATE_COMPLETED,
            ], true)) {
                return $attempt;
            }

            $this->assertState($attempt, MerchantSettlementAttempt::STATE_BROADCASTED);
            $attempt->state = MerchantSettlementAttempt::STATE_CONFIRMED;
            $attempt->confirmed_at = now('UTC');
            $attempt->metadata = array_merge($attempt->metadata ?? [], ['confirmation_evidence' => $evidence]);
            $attempt->save();

            return $attempt;
        });
    }

    /**
     * Only a chain-confirmed attempt may create accounting and mark forwarding done.
     */
    public function complete(int $attemptId): SettlementCompletionResult
    {
        return DB::transaction(function () use ($attemptId): SettlementCompletionResult {
            [$attempt, $invoice] = $this->lockSettlementContext($attemptId);

            if ($attempt->state === MerchantSettlementAttempt::STATE_COMPLETED) {
                return new SettlementCompletionResult(
                    $invoice,
                    false,
                );
            }

            $this->assertState($attempt, MerchantSettlementAttempt::STATE_CONFIRMED);

            if ($attempt->txid === null || $attempt->txid === '') {
                throw new RuntimeException("Settlement attempt [{$attempt->attempt_uuid}] has no txid.");
            }

            if (
                $invoice->forward_attempt_uuid !== $attempt->attempt_uuid
                || $invoice->status !== 'paid'
                || $invoice->settlement_snapshot_locked_at === null
                || $invoice->merchant_id !== $attempt->merchant_id
                || $invoice->resolvedAssetKey() !== $attempt->asset_key
                || $invoice->resolvedNetworkKey() !== $attempt->network_key
                || ! $this->decimalEquals($invoice->fee_coin, $attempt->fee_coin_snapshot)
                || ! $this->decimalEquals($invoice->merchant_payout_coin, $attempt->merchant_payout_coin_snapshot)
            ) {
                throw new RuntimeException(
                    "Invoice [{$invoice->id}] no longer matches settlement attempt [{$attempt->attempt_uuid}]."
                );
            }

            $assetKey = $attempt->asset_key;
            $amount = $this->decimal->asset($attempt->broadcast_amount_coin ?? $attempt->amount_coin, $assetKey);
            $reservedAmount = $this->decimal->asset($attempt->amount_coin, $assetKey);

            if ($amount->compareTo(BigDecimal::zero()) <= 0 || $amount->compareTo($reservedAmount) > 0) {
                throw new RuntimeException(
                    "Settlement attempt [{$attempt->attempt_uuid}] has invalid broadcast amount [{$amount}]."
                );
            }

            $newForwarded = $this->decimal->asset($this->amounts->recordedForwardedCoin($invoice), $assetKey)
                ->plus($amount);
            $targetNet = $this->decimal->asset($this->amounts->targetNetCoin($invoice), $assetKey);
            $remaining = $this->decimal->positiveOrZero($targetNet->minus($newForwarded), $assetKey);

            $invoice->forwarded_coin = (string) $this->decimal->asset($newForwarded, $assetKey);
            $invoice->forward_txids = array_values(array_unique(array_merge(
                $invoice->forward_txids ?? [],
                [$attempt->txid],
            )));
            $invoice->last_forwarded_at = now('UTC');
            $invoice->forward_status = $remaining->isZero()
                ? Invoice::FORWARD_STATUS_DONE
                : Invoice::FORWARD_STATUS_PARTIAL;
            $invoice->forward_attempt_uuid = null;
            $invoice->forwarding_coin = null;
            $invoice->forwarding_started_at = null;
            $invoice->save();

            $attempt->state = MerchantSettlementAttempt::STATE_COMPLETED;
            $attempt->completed_at = now('UTC');
            $attempt->retry_safe = false;
            $attempt->reconciliation_owner_token = null;
            $attempt->reconciliation_lease_expires_at = null;
            $attempt->next_reconciliation_at = null;
            $attempt->save();

            $this->ledger->markForwardCompleted($invoice, $attempt, (string) $amount);
            $this->enqueueWebhook->enqueue(
                'invoice.forwarded',
                $invoice,
                "invoice:{$invoice->id}:event:invoice.forwarded:attempt:{$attempt->attempt_uuid}",
            );

            return new SettlementCompletionResult($invoice, true);
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function confirmAndComplete(int $attemptId, array $evidence = []): SettlementCompletionResult
    {
        $this->markConfirmed($attemptId, $evidence);

        return $this->complete($attemptId);
    }

    public function markPreBroadcastFailed(int $attemptId, ?string $errorMessage = null): void
    {
        DB::transaction(function () use ($attemptId, $errorMessage): void {
            [$attempt, $invoice] = $this->lockSettlementContext($attemptId);

            if ($attempt->state === MerchantSettlementAttempt::STATE_FAILED && $attempt->retry_safe) {
                return;
            }

            if ($attempt->state !== MerchantSettlementAttempt::STATE_RESERVED) {
                $this->quarantineLockedAttempt($attempt, $invoice, $errorMessage ?? 'Failure occurred after broadcast could have started.');

                return;
            }

            $this->failRetrySafeLocked($attempt, $invoice, $errorMessage);
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function markProvenFailed(int $attemptId, string $reason, array $evidence): void
    {
        DB::transaction(function () use ($attemptId, $reason, $evidence): void {
            [$attempt, $invoice] = $this->lockSettlementContext($attemptId);

            if ($attempt->state === MerchantSettlementAttempt::STATE_FAILED && $attempt->retry_safe) {
                return;
            }

            if (! in_array($attempt->state, [
                MerchantSettlementAttempt::STATE_BROADCASTED,
                MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
            ], true)) {
                throw new RuntimeException(
                    "Settlement attempt [{$attempt->attempt_uuid}] cannot become retry-safe from [{$attempt->state}]."
                );
            }

            $attempt->metadata = array_merge($attempt->metadata ?? [], ['failed_chain_evidence' => $evidence]);
            $this->failRetrySafeLocked($attempt, $invoice, $reason);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markNeedsReconciliation(int $attemptId, ?string $errorMessage = null, array $metadata = []): void
    {
        DB::transaction(function () use ($attemptId, $errorMessage, $metadata): void {
            [$attempt, $invoice] = $this->lockSettlementContext($attemptId);

            if ($attempt->state === MerchantSettlementAttempt::STATE_COMPLETED) {
                return;
            }

            $attempt->metadata = array_merge($attempt->metadata ?? [], $metadata);
            if ($attempt->state === MerchantSettlementAttempt::STATE_CONFIRMED) {
                $attempt->error_message = $errorMessage;
                $attempt->reconciliation_required_at ??= now('UTC');
                $attempt->next_reconciliation_at ??= now('UTC');
                $attempt->save();

                $invoice->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
                $invoice->save();

                return;
            }

            $this->quarantineLockedAttempt($attempt, $invoice, $errorMessage);
        });
    }

    public function failReservedForGateLocked(
        MerchantSettlementAttempt $attempt,
        Invoice $invoice,
        string $reason,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Failing a reserved attempt at the gate requires an active transaction.');
        }

        if ($attempt->state === MerchantSettlementAttempt::STATE_FAILED && $attempt->retry_safe) {
            return;
        }

        if ($attempt->state !== MerchantSettlementAttempt::STATE_RESERVED) {
            throw new RuntimeException(
                "Settlement attempt [{$attempt->attempt_uuid}] already crossed the broadcast boundary.",
            );
        }

        $this->failRetrySafeLocked($attempt, $invoice, $reason);
    }

    public function heartbeatReserved(int $attemptId, string $ownerToken): bool
    {
        return DB::transaction(function () use ($attemptId, $ownerToken): bool {
            $attempt = $this->lockAttempt($attemptId);
            if ($attempt->state !== MerchantSettlementAttempt::STATE_RESERVED || $attempt->lease_owner_token !== $ownerToken) {
                return false;
            }

            $this->refreshLease($attempt);
            $attempt->save();

            return true;
        });
    }

    public function reapExpiredReservations(int $limit = 100): int
    {
        $ids = MerchantSettlementAttempt::query()
            ->where('state', MerchantSettlementAttempt::STATE_RESERVED)
            ->where('lease_expires_at', '<=', now('UTC'))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');
        $reaped = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$reaped): void {
                [$attempt, $invoice] = $this->lockSettlementContext((int) $id);
                if (
                    $attempt->state !== MerchantSettlementAttempt::STATE_RESERVED
                    || $attempt->lease_expires_at === null
                    || $attempt->lease_expires_at->isFuture()
                ) {
                    return;
                }

                $this->failRetrySafeLocked($attempt, $invoice, 'Reservation ownership lease expired before broadcast.');
                $reaped++;
            });
        }

        return $reaped;
    }

    private function failRetrySafeLocked(
        MerchantSettlementAttempt $attempt,
        Invoice $invoice,
        ?string $errorMessage,
    ): void {
        $attempt->state = MerchantSettlementAttempt::STATE_FAILED;
        $attempt->retry_safe = true;
        $attempt->error_message = $errorMessage;
        $attempt->failed_at = now('UTC');
        $attempt->lease_owner_token = null;
        $attempt->lease_expires_at = null;
        $attempt->heartbeat_at = null;
        $attempt->reconciliation_owner_token = null;
        $attempt->reconciliation_lease_expires_at = null;
        $attempt->next_reconciliation_at = null;
        $attempt->save();

        $this->markInvoiceFailedForRetry($attempt, $invoice);
    }

    private function quarantineLockedAttempt(
        MerchantSettlementAttempt $attempt,
        Invoice $invoice,
        ?string $errorMessage,
    ): void {
        $attempt->state = MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION;
        $attempt->retry_safe = false;
        $attempt->error_message = $errorMessage;
        $attempt->reconciliation_required_at ??= now('UTC');
        $attempt->next_reconciliation_at ??= now('UTC');
        $attempt->lease_expires_at = null;
        $attempt->save();

        $invoice->forward_status = Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION;
        $invoice->forward_attempt_uuid = $attempt->attempt_uuid;
        $invoice->forwarding_coin = $attempt->amount_coin;
        $invoice->forwarding_started_at ??= $attempt->reserved_at;
        $invoice->save();
    }

    private function markInvoiceProcessing(MerchantSettlementAttempt $attempt, Invoice $invoice): void
    {
        $invoice->forward_status = Invoice::FORWARD_STATUS_PROCESSING;
        $invoice->forward_attempt_uuid = $attempt->attempt_uuid;
        $invoice->forwarding_coin = $attempt->amount_coin;
        $invoice->save();
    }

    private function markInvoiceFailedForRetry(MerchantSettlementAttempt $attempt, Invoice $invoice): void
    {
        if ($invoice->forward_attempt_uuid !== $attempt->attempt_uuid) {
            throw new RuntimeException(
                "Invoice [{$invoice->id}] no longer references settlement attempt [{$attempt->attempt_uuid}]."
            );
        }

        $invoice->forward_status = Invoice::FORWARD_STATUS_FAILED;
        $invoice->forward_attempt_uuid = null;
        $invoice->forwarding_coin = null;
        $invoice->forwarding_started_at = null;
        $invoice->save();
    }

    private function assertReservedOwner(MerchantSettlementAttempt $attempt, ?string $ownerToken): void
    {
        $this->assertState($attempt, MerchantSettlementAttempt::STATE_RESERVED);

        if ($ownerToken !== null && $attempt->lease_owner_token !== $ownerToken) {
            throw new RuntimeException("Settlement attempt [{$attempt->attempt_uuid}] lease owner mismatch.");
        }

        if ($attempt->lease_expires_at === null || $attempt->lease_expires_at->isPast()) {
            throw new RuntimeException("Settlement attempt [{$attempt->attempt_uuid}] reservation lease expired.");
        }
    }

    private function refreshLease(MerchantSettlementAttempt $attempt): void
    {
        $leaseSeconds = max(30, (int) config('forwarding.attempts.reservation_lease_seconds', 300));
        $attempt->heartbeat_at = now('UTC');
        $attempt->lease_expires_at = now('UTC')->addSeconds($leaseSeconds);
    }

    /** @return array{MerchantSettlementAttempt, Invoice} */
    private function lockSettlementContext(int $attemptId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Settlement context locking requires an active database transaction.');
        }

        /** @var MerchantSettlementAttempt $identity */
        $identity = MerchantSettlementAttempt::query()
            ->select(['id', 'invoice_id', 'merchant_id'])
            ->findOrFail($attemptId);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($identity->invoice_id);

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->lockForUpdate()->findOrFail($invoice->merchant_id);
        $invoice->setRelation('merchant', $merchant);

        $attempt = $this->lockAttempt($attemptId);
        if ($attempt->invoice_id !== $invoice->id || $attempt->merchant_id !== $merchant->id) {
            throw new RuntimeException("Settlement attempt [{$attempt->attempt_uuid}] identity changed while locking.");
        }

        return [$attempt, $invoice];
    }

    private function lockAttempt(int $attemptId): MerchantSettlementAttempt
    {
        /** @var MerchantSettlementAttempt $attempt */
        $attempt = MerchantSettlementAttempt::query()->lockForUpdate()->findOrFail($attemptId);

        return $attempt;
    }

    private function assertState(MerchantSettlementAttempt $attempt, string $expected): void
    {
        if ($attempt->state !== $expected) {
            throw new RuntimeException(
                "Settlement attempt [{$attempt->attempt_uuid}] expected [{$expected}], got [{$attempt->state}]."
            );
        }
    }

    private function decimalEquals(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return BigDecimal::of((string) $left)->compareTo(BigDecimal::of((string) $right)) === 0;
    }
}
