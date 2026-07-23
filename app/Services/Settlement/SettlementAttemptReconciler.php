<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Contracts\SettlementAttemptEvidenceProviderInterface;
use App\Data\SettlementReconciliationResult;
use App\Models\MerchantSettlementAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SettlementAttemptReconciler
{
    public function __construct(
        private SettlementAttemptEvidenceProviderInterface $evidenceProvider,
        private MerchantSettlementAttemptManager $attempts,
    ) {}

    public function reconcile(int $attemptId, bool $force = false): ?SettlementReconciliationResult
    {
        $ownerToken = (string) Str::uuid();
        $attempt = $this->claim($attemptId, $ownerToken, $force);
        if ($attempt === null) {
            return null;
        }

        try {
            if ($attempt->state === MerchantSettlementAttempt::STATE_CONFIRMED) {
                $this->attempts->complete($attempt->id);

                return SettlementReconciliationResult::confirmed(
                    (string) $attempt->txid,
                    max(1, (int) $attempt->required_confirmations),
                    ['recovered_confirmed_finalization' => true],
                );
            }

            $result = $this->evidenceProvider->inspect($attempt);

            if (
                $result->txid !== null
                && ($attempt->txid === null || $attempt->state === MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION)
            ) {
                $attempt = $this->attempts->recordRecoveredBroadcast(
                    $attempt->id,
                    $result->txid,
                    $result->evidence,
                );
            }

            if ($result->outcome === SettlementReconciliationResult::CONFIRMED) {
                $this->attempts->confirmAndComplete(
                    $attempt->id,
                    array_merge($result->evidence, ['confirmations' => $result->confirmations]),
                );
            } elseif ($result->outcome === SettlementReconciliationResult::FAILED_SAFE) {
                $this->attempts->markProvenFailed($attempt->id, $result->reason, $result->evidence);
            } elseif ($result->outcome === SettlementReconciliationResult::INCONCLUSIVE) {
                $this->attempts->markNeedsReconciliation(
                    $attempt->id,
                    $result->reason,
                    ['last_reconciliation_evidence' => $result->evidence],
                );
            }

            return $result;
        } catch (Throwable $e) {
            $this->attempts->markNeedsReconciliation(
                $attempt->id,
                $e->getMessage(),
                ['reconciliation_failure_class' => $e::class],
            );

            throw $e;
        } finally {
            $this->releaseClaim($attemptId, $ownerToken);
        }
    }

    private function claim(int $attemptId, string $ownerToken, bool $force): ?MerchantSettlementAttempt
    {
        return DB::transaction(function () use ($attemptId, $ownerToken, $force): ?MerchantSettlementAttempt {
            /** @var MerchantSettlementAttempt $attempt */
            $attempt = MerchantSettlementAttempt::query()->lockForUpdate()->findOrFail($attemptId);

            if (! in_array($attempt->state, [
                MerchantSettlementAttempt::STATE_BROADCASTING,
                MerchantSettlementAttempt::STATE_BROADCASTED,
                MerchantSettlementAttempt::STATE_CONFIRMED,
                MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
            ], true)) {
                return null;
            }

            if (
                ! $force
                && $attempt->next_reconciliation_at !== null
                && $attempt->next_reconciliation_at->isFuture()
            ) {
                return null;
            }

            if (
                $attempt->reconciliation_owner_token !== null
                && $attempt->reconciliation_lease_expires_at !== null
                && $attempt->reconciliation_lease_expires_at->isFuture()
            ) {
                return null;
            }

            $attemptNumber = ((int) $attempt->reconciliation_attempts) + 1;
            $attempt->reconciliation_attempts = $attemptNumber;
            $attempt->last_reconciled_at = now('UTC');
            $attempt->next_reconciliation_at = now('UTC')->addSeconds($this->backoffSeconds($attemptNumber));
            $attempt->reconciliation_owner_token = $ownerToken;
            $attempt->reconciliation_lease_expires_at = now('UTC')->addSeconds(
                max(30, (int) config('forwarding.attempts.reconciliation_lease_seconds', 120)),
            );
            $attempt->save();

            return $attempt->fresh();
        });
    }

    private function releaseClaim(int $attemptId, string $ownerToken): void
    {
        DB::transaction(function () use ($attemptId, $ownerToken): void {
            /** @var MerchantSettlementAttempt|null $attempt */
            $attempt = MerchantSettlementAttempt::query()->lockForUpdate()->find($attemptId);
            if ($attempt === null || $attempt->reconciliation_owner_token !== $ownerToken) {
                return;
            }

            $attempt->reconciliation_owner_token = null;
            $attempt->reconciliation_lease_expires_at = null;
            $attempt->save();
        });
    }

    private function backoffSeconds(int $attemptNumber): int
    {
        $configured = config('forwarding.attempts.reconciliation_backoff_seconds', [15, 60, 300, 900, 3600]);
        $backoff = array_values(array_filter(
            is_array($configured) ? $configured : [],
            static fn (mixed $seconds): bool => is_numeric($seconds) && (int) $seconds > 0,
        ));
        $backoff = $backoff !== [] ? $backoff : [15, 60, 300, 900, 3600];

        return (int) $backoff[min(max(0, $attemptNumber - 1), count($backoff) - 1)];
    }
}
