<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EvmGasFunding;
use App\Services\Evm\EvmGasFundingReconciler;
use Illuminate\Console\Command;

final class ReconcileEvmGasFundingsCommand extends Command
{
    protected $signature = 'settlements:reconcile-gas-fundings
        {--funding= : Funding numeric ID or UUID}
        {--limit=100 : Maximum due funding records to reconcile}';

    protected $description = 'Safely reconcile EVM gas-sponsorship broadcasts against chain evidence';

    public function handle(EvmGasFundingReconciler $reconciler): int
    {
        $query = EvmGasFunding::query()->where(function ($query): void {
            $query->whereIn('state', [
                EvmGasFunding::STATE_BROADCASTING,
                EvmGasFunding::STATE_BROADCASTED,
                EvmGasFunding::STATE_NEEDS_RECONCILIATION,
            ])->orWhere(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('state', EvmGasFunding::STATE_CONFIRMED)
                        ->orWhere(function ($query): void {
                            $query->where('state', EvmGasFunding::STATE_FAILED)
                                ->where('retry_safe', true);
                        });
                })->whereHas('invoice', function ($query): void {
                    $query->where('status', 'paid')
                        ->whereNull('forward_attempt_uuid')
                        ->whereIn('forward_status', ['none', 'partial', 'failed']);
                });
            });
        });

        $fundingOption = trim((string) $this->option('funding'));
        if ($fundingOption !== '') {
            $query->where(function ($query) use ($fundingOption): void {
                $query->where('funding_uuid', $fundingOption);
                if (ctype_digit($fundingOption)) {
                    $query->orWhereKey((int) $fundingOption);
                }
            });
        } else {
            $query->where(function ($query): void {
                $query->whereNull('next_reconciliation_at')
                    ->orWhere('next_reconciliation_at', '<=', now('UTC'));
            });
        }

        $fundings = $query->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();
        foreach ($fundings as $funding) {
            $result = $reconciler->reconcile($funding->id, $fundingOption !== '');
            $this->line(sprintf('%s: %s', $funding->funding_uuid, $result?->outcome ?? 'skipped'));
        }

        $this->info("Processed {$fundings->count()} EVM gas funding record(s).");

        return self::SUCCESS;
    }
}
