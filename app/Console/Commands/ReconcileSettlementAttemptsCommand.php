<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MerchantSettlementAttempt;
use App\Services\Settlement\SettlementAttemptReconciler;
use Illuminate\Console\Command;

final class ReconcileSettlementAttemptsCommand extends Command
{
    protected $signature = 'settlements:reconcile-attempts
        {--attempt= : Attempt numeric ID or UUID}
        {--limit=100 : Maximum due attempts to reconcile}';

    protected $description = 'Safely reconcile settlement broadcasts against chain evidence';

    public function handle(SettlementAttemptReconciler $reconciler): int
    {
        $query = MerchantSettlementAttempt::query()
            ->whereIn('state', [
                MerchantSettlementAttempt::STATE_BROADCASTING,
                MerchantSettlementAttempt::STATE_BROADCASTED,
                MerchantSettlementAttempt::STATE_CONFIRMED,
                MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
            ]);

        $attemptOption = trim((string) $this->option('attempt'));
        if ($attemptOption !== '') {
            $query->where(function ($query) use ($attemptOption): void {
                $query->where('attempt_uuid', $attemptOption);
                if (ctype_digit($attemptOption)) {
                    $query->orWhereKey((int) $attemptOption);
                }
            });
        } else {
            $query->where(function ($query): void {
                $query->whereNull('next_reconciliation_at')
                    ->orWhere('next_reconciliation_at', '<=', now('UTC'));
            });
        }

        $attempts = $query->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();
        foreach ($attempts as $attempt) {
            $result = $reconciler->reconcile($attempt->id, $attemptOption !== '');
            $this->line(sprintf(
                '%s: %s',
                $attempt->attempt_uuid,
                $result?->outcome ?? 'skipped',
            ));
        }

        $this->info("Processed {$attempts->count()} settlement attempt(s).");

        return self::SUCCESS;
    }
}
