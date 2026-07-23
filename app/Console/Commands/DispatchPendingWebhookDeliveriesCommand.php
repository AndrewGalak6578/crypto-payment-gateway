<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchPendingWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:dispatch-pending {--limit= : Maximum due deliveries to dispatch}';

    protected $description = 'Recover pending durable webhook deliveries whose queue dispatch was lost';

    public function handle(): int
    {
        $staleBefore = now('UTC')->subSeconds(
            max(30, (int) config('webhooks.delivering_stale_seconds', 120)),
        );
        $deliveries = WebhookDelivery::query()
            ->where(function ($query) use ($staleBefore): void {
                $query->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->where(function ($query): void {
                            $query->whereNull('next_retry_at')
                                ->orWhere('next_retry_at', '<=', now('UTC'));
                        });
                })->orWhere(function ($query) use ($staleBefore): void {
                    $query->where('status', 'delivering')
                        ->where('updated_at', '<=', $staleBefore);
                });
            })
            ->orderBy('id')
            ->limit(max(1, (int) ($this->option('limit') ?: config('webhooks.pending_recovery_limit', 100))))
            ->get();

        foreach ($deliveries as $delivery) {
            $deliveryId = DB::transaction(function () use ($delivery, $staleBefore): ?int {
                /** @var WebhookDelivery|null $locked */
                $locked = WebhookDelivery::query()->lockForUpdate()->find($delivery->id);
                if ($locked === null) {
                    return null;
                }

                $pendingDue = $locked->status === 'pending'
                    && ($locked->next_retry_at === null || $locked->next_retry_at->isPast());
                $staleDelivering = $locked->status === 'delivering'
                    && $locked->updated_at !== null
                    && $locked->updated_at->lessThanOrEqualTo($staleBefore);

                if (! $pendingDue && ! $staleDelivering) {
                    return null;
                }

                if ($staleDelivering) {
                    $locked->status = 'pending';
                    $locked->next_retry_at = null;
                    $locked->last_error = 'Recovered stale delivering lease; prior HTTP outcome may be ambiguous.';
                    $locked->save();
                }

                return $locked->id;
            });

            if ($deliveryId !== null) {
                DeliverWebhookJob::dispatch($deliveryId);
            }
        }

        $this->info("Inspected {$deliveries->count()} recoverable webhook delivery record(s).");

        return self::SUCCESS;
    }
}
