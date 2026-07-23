<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvmGasFunding;
use App\Services\Evm\EvmGasFundingReconciler;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileEvmGasFundingJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 7200;

    public function __construct(public int $fundingId) {}

    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->fundingId;
    }

    public function handle(EvmGasFundingReconciler $reconciler): void
    {
        $reconciler->reconcile($this->fundingId);

        if ((string) config('queue.default', 'sync') === 'sync') {
            return;
        }

        $funding = EvmGasFunding::query()->find($this->fundingId);
        if (
            $funding === null
            || ! in_array($funding->state, [
                EvmGasFunding::STATE_BROADCASTING,
                EvmGasFunding::STATE_BROADCASTED,
                EvmGasFunding::STATE_NEEDS_RECONCILIATION,
            ], true)
        ) {
            return;
        }

        $nextRunAt = $funding->next_reconciliation_at ?? now('UTC')->addMinute();
        if ($funding->reconciliation_lease_expires_at?->greaterThan($nextRunAt)) {
            $nextRunAt = $funding->reconciliation_lease_expires_at;
        }

        self::dispatch($funding->id)->delay($nextRunAt);
    }
}
