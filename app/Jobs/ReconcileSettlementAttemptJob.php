<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MerchantSettlementAttempt;
use App\Services\Settlement\SettlementAttemptReconciler;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileSettlementAttemptJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 7200;

    public function __construct(public int $attemptId) {}

    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->attemptId;
    }

    public function handle(SettlementAttemptReconciler $reconciler): void
    {
        $reconciler->reconcile($this->attemptId);

        if ((string) config('queue.default', 'sync') === 'sync') {
            return;
        }

        $attempt = MerchantSettlementAttempt::query()->find($this->attemptId);
        if (
            $attempt === null
            || ! in_array($attempt->state, [
                MerchantSettlementAttempt::STATE_BROADCASTING,
                MerchantSettlementAttempt::STATE_BROADCASTED,
                MerchantSettlementAttempt::STATE_CONFIRMED,
                MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
            ], true)
        ) {
            return;
        }

        $nextRunAt = $attempt->next_reconciliation_at ?? now('UTC')->addMinute();
        if (
            $attempt->reconciliation_lease_expires_at !== null
            && $attempt->reconciliation_lease_expires_at->greaterThan($nextRunAt)
        ) {
            $nextRunAt = $attempt->reconciliation_lease_expires_at;
        }

        self::dispatch($attempt->id)->delay($nextRunAt);
    }
}
