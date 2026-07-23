<?php

declare(strict_types=1);

namespace App\Services\Evm;

use App\Contracts\EvmGasFundingEvidenceProviderInterface;
use App\Data\EvmGasFundingReconciliationResult;
use App\Jobs\ForwardInvoiceJob;
use App\Models\EvmGasFunding;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class EvmGasFundingReconciler
{
    public function __construct(private EvmGasFundingEvidenceProviderInterface $evidenceProvider) {}

    public function reconcile(int $fundingId, bool $force = false): ?EvmGasFundingReconciliationResult
    {
        $ownerToken = (string) Str::uuid();
        $funding = $this->claim($fundingId, $ownerToken, $force);
        if ($funding === null) {
            return null;
        }

        try {
            if (in_array($funding->state, [EvmGasFunding::STATE_CONFIRMED, EvmGasFunding::STATE_FAILED], true)) {
                $this->dispatchContinuationWhenEligible($funding);

                return $funding->state === EvmGasFunding::STATE_CONFIRMED
                    ? EvmGasFundingReconciliationResult::confirmed(
                        (string) $funding->tx_hash,
                        max(1, (int) $funding->required_confirmations),
                        ['recovered_continuation' => true],
                    )
                    : EvmGasFundingReconciliationResult::failedSafe(
                        (string) $funding->tx_hash,
                        max(1, (int) $funding->required_confirmations),
                        ['recovered_continuation' => true],
                    );
            }

            $result = $this->evidenceProvider->inspect($funding);
            $this->applyResult($funding->id, $result);

            if (in_array($result->outcome, [
                EvmGasFundingReconciliationResult::CONFIRMED,
                EvmGasFundingReconciliationResult::FAILED_SAFE,
            ], true)) {
                $this->dispatchContinuationWhenEligible($funding->fresh());
            }

            return $result;
        } catch (Throwable $e) {
            $result = EvmGasFundingReconciliationResult::inconclusive(
                'gas_funding_reconciliation_error',
                $funding->tx_hash,
                ['error' => $e->getMessage(), 'failure_class' => $e::class],
            );
            $fresh = $funding->fresh();
            if (! in_array($fresh->state, [EvmGasFunding::STATE_CONFIRMED, EvmGasFunding::STATE_FAILED], true)) {
                $this->applyResult($funding->id, $result);
            }

            return $result;
        } finally {
            $this->releaseClaim($fundingId, $ownerToken);
        }
    }

    private function claim(int $fundingId, string $ownerToken, bool $force): ?EvmGasFunding
    {
        return DB::transaction(function () use ($fundingId, $ownerToken, $force): ?EvmGasFunding {
            /** @var EvmGasFunding $funding */
            $funding = EvmGasFunding::query()->lockForUpdate()->findOrFail($fundingId);

            $eligible = in_array($funding->state, [
                EvmGasFunding::STATE_BROADCASTING,
                EvmGasFunding::STATE_BROADCASTED,
                EvmGasFunding::STATE_NEEDS_RECONCILIATION,
                EvmGasFunding::STATE_CONFIRMED,
            ], true) || ($funding->state === EvmGasFunding::STATE_FAILED && $funding->retry_safe);

            if (! $eligible) {
                return null;
            }

            if (! $force && $funding->next_reconciliation_at?->isFuture()) {
                return null;
            }

            if (
                $funding->reconciliation_owner_token !== null
                && $funding->reconciliation_lease_expires_at?->isFuture()
            ) {
                return null;
            }

            $attemptNumber = ((int) $funding->reconciliation_attempts) + 1;
            $funding->reconciliation_attempts = $attemptNumber;
            $funding->last_reconciled_at = now('UTC');
            $funding->next_reconciliation_at = now('UTC')->addSeconds($this->backoffSeconds($attemptNumber));
            $funding->reconciliation_owner_token = $ownerToken;
            $funding->reconciliation_lease_expires_at = now('UTC')->addSeconds(
                max(30, (int) config('payment_addresses.evm.gas_topup.reconciliation_lease_seconds', 120)),
            );
            $funding->save();

            return $funding->fresh();
        });
    }

    private function applyResult(int $fundingId, EvmGasFundingReconciliationResult $result): void
    {
        DB::transaction(function () use ($fundingId, $result): void {
            /** @var EvmGasFunding $funding */
            $funding = EvmGasFunding::query()->lockForUpdate()->findOrFail($fundingId);

            if ($result->txHash !== null) {
                if ($funding->tx_hash !== null && strtolower($funding->tx_hash) !== strtolower($result->txHash)) {
                    $result = EvmGasFundingReconciliationResult::inconclusive(
                        'conflicting_recovered_transaction_hash',
                        $funding->tx_hash,
                        $result->evidence,
                    );
                } else {
                    $funding->tx_hash = strtolower($result->txHash);
                    $funding->broadcasted_at ??= now('UTC');
                }
            }

            $funding->meta = array_merge($funding->meta ?? [], [
                'last_reconciliation' => [
                    'outcome' => $result->outcome,
                    'reason' => $result->reason,
                    'confirmations' => $result->confirmations,
                    'evidence' => $result->evidence,
                ],
            ]);

            if ($result->outcome === EvmGasFundingReconciliationResult::CONFIRMED) {
                $funding->state = EvmGasFunding::STATE_CONFIRMED;
                $funding->status = 'confirmed';
                $funding->retry_safe = false;
                $funding->confirmed_at ??= now('UTC');
                $funding->error_message = null;
                $funding->next_reconciliation_at = null;
            } elseif ($result->outcome === EvmGasFundingReconciliationResult::FAILED_SAFE) {
                $funding->state = EvmGasFunding::STATE_FAILED;
                $funding->status = 'failed';
                $funding->retry_safe = true;
                $funding->failed_at ??= now('UTC');
                $funding->error_message = $result->reason;
                $funding->next_reconciliation_at = null;
            } elseif ($result->outcome === EvmGasFundingReconciliationResult::PENDING) {
                $funding->state = EvmGasFunding::STATE_BROADCASTED;
                $funding->status = 'submitted';
                $funding->retry_safe = false;
                $funding->error_message = null;
            } else {
                $funding->state = EvmGasFunding::STATE_NEEDS_RECONCILIATION;
                $funding->status = 'needs_reconciliation';
                $funding->retry_safe = false;
                $funding->error_message = $result->reason;
                $funding->reconciliation_required_at ??= now('UTC');
            }

            $funding->save();
        });
    }

    private function dispatchContinuationWhenEligible(EvmGasFunding $funding): void
    {
        if ($funding->invoice_id === null) {
            return;
        }

        DB::transaction(function () use ($funding): void {
            /** @var EvmGasFunding|null $lockedFunding */
            $lockedFunding = EvmGasFunding::query()->lockForUpdate()->find($funding->id);
            if (
                $lockedFunding === null
                || ! (
                    $lockedFunding->state === EvmGasFunding::STATE_CONFIRMED
                    || (
                        $lockedFunding->state === EvmGasFunding::STATE_FAILED
                        && $lockedFunding->retry_safe
                    )
                )
                || $lockedFunding->invoice_id === null
            ) {
                return;
            }

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->lockForUpdate()->find($lockedFunding->invoice_id);
            if (
                $invoice === null
                || $invoice->status !== 'paid'
                || ! $invoice->hasRetryableForwardStatus()
                || $invoice->forward_attempt_uuid !== null
            ) {
                return;
            }

            $now = now('UTC');
            $cooldownSeconds = max(30, (int) config(
                'payment_addresses.evm.gas_topup.continuation_stale_seconds',
                300,
            ));
            if (
                $lockedFunding->continuation_dispatched_at !== null
                && $lockedFunding->continuation_dispatched_at->greaterThan(
                    $now->copy()->subSeconds($cooldownSeconds),
                )
            ) {
                return;
            }

            $lockedFunding->continuation_dispatched_at = $now;
            $lockedFunding->save();

            $invoiceId = $invoice->id;
            DB::afterCommit(static function () use ($invoiceId): void {
                ForwardInvoiceJob::dispatch($invoiceId);
            });
        });
    }

    private function releaseClaim(int $fundingId, string $ownerToken): void
    {
        DB::transaction(function () use ($fundingId, $ownerToken): void {
            /** @var EvmGasFunding|null $funding */
            $funding = EvmGasFunding::query()->lockForUpdate()->find($fundingId);
            if ($funding === null || $funding->reconciliation_owner_token !== $ownerToken) {
                return;
            }

            $funding->reconciliation_owner_token = null;
            $funding->reconciliation_lease_expires_at = null;
            $funding->save();
        });
    }

    private function backoffSeconds(int $attemptNumber): int
    {
        $configured = config(
            'payment_addresses.evm.gas_topup.reconciliation_backoff_seconds',
            [15, 60, 300, 900, 3600],
        );
        $backoff = array_values(array_filter(
            is_array($configured) ? $configured : [],
            static fn (mixed $seconds): bool => is_numeric($seconds) && (int) $seconds > 0,
        ));
        $backoff = $backoff !== [] ? $backoff : [15, 60, 300, 900, 3600];

        return (int) $backoff[min(max(0, $attemptNumber - 1), count($backoff) - 1)];
    }
}
