<?php
declare(strict_types=1);
namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WebhookDeliverySender
{
    public function send(int $deliveryId): void
    {
        $delivery = DB::transaction(function () use ($deliveryId): ?WebhookDelivery {
            /** @var WebhookDelivery|null $delivery */
            $delivery = WebhookDelivery::query()
                ->lockForUpdate()
                ->find($deliveryId);

            if (! $delivery) {
                return null;
            }

            if ($delivery->status === 'delivered') {
                return null;
            }

            if ($delivery->status === 'failed') {
                return null;
            }

            if ($delivery->next_retry_at && $delivery->next_retry_at->isFuture()) {
                return null;
            }

            $delivery->status = 'delivering';
            $delivery->save();

            return $delivery->fresh();
        });

        if (! $delivery) {
            return;
        }

        try {
            $response = Http::timeout((int) config('webhooks.timeout_sec', 10))
                ->acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $delivery->signature,
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Delivery-Id' => (string) $delivery->id,
                ])
                ->post($delivery->url, $delivery->payload);

            if ($response->successful()) {
                $this->markDelivered($delivery->id);
                return;
            }

            $this->markRetryableFailure(
                $delivery->id,
                'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 2000)
            );
        } catch (Throwable $e) {
            $this->markRetryableFailure($delivery->id, mb_substr($e->getMessage(), 0, 2000));
            report($e);
        }
    }

    private function markDelivered(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            /** @var WebhookDelivery $delivery */
            $delivery = WebhookDelivery::query()
                ->lockForUpdate()
                ->findOrFail($deliveryId);

            $delivery->attempts++;
            $delivery->status = 'delivered';
            $delivery->delivered_at = now('UTC');
            $delivery->next_retry_at = null;
            $delivery->last_error = null;
            $delivery->save();
        });
    }

    private function markRetryableFailure(int $deliveryId, string $error): void
    {
        $maxAttempts = (int) config('webhook.retries.max_attempts', 6);
        $schedule = config('webhook.retries.backoff_sec', [60, 300, 900, 3600, 10800]);

        $shouldRedispatch = false;
        $delaySeconds = null;

        DB::transaction(function () use (
            $deliveryId,
            $error,
            $maxAttempts,
            $schedule,
            &$shouldRedispatch,
            &$delaySeconds
        ): void {
            /** @var WebhookDelivery $delivery */
            $delivery = WebhookDelivery::query()
                ->lockForUpdate()
                ->findOrFail($deliveryId);

            $delivery->attempts++;
            $delivery->last_error = $error;

            if ($delivery->attempts >= $maxAttempts) {
                $delivery->status = 'failed';
                $delivery->next_retry_at = null;
                $delivery->save();

                return;
            }

            $index = max(0, min($delivery->attempts - 1, count($schedule) - 1));
            $delaySeconds = (int) $schedule[$index];

            $delivery->status = 'pending';
            $delivery->next_retry_at = now('UTC')->addSeconds($delaySeconds);
            $delivery->save();

            $shouldRedispatch = true;
        });

        if ($shouldRedispatch && $delaySeconds !== null) {
            DeliverWebhookJob::dispatch($deliveryId)->delay(now('UTC')->addSeconds($delaySeconds));
        }
    }
}
