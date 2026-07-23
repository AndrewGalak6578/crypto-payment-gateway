<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\Invoice;
use App\Models\WebhookDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Persists invoice webhook delivery and dispatches async sender job.
 */
final class EnqueueInvoiceWebhook
{
    public function __construct(
        private readonly InvoiceWebhookPayloadFactory $payloadFactory,
        private readonly WebhookSignature $signature,
    ) {}

    /**
     * @param  non-empty-string  $event
     * @param  Invoice  $invoice  Invoice snapshot with loaded merchant relation.
     */
    public function enqueue(
        string $event,
        Invoice $invoice,
        ?string $idempotencyKey = null,
    ): ?WebhookDelivery {
        $delivery = $this->persist($event, $invoice, $idempotencyKey);
        if ($delivery !== null) {
            $this->dispatchAfterCommit($delivery);
        }

        return $delivery;
    }

    public function persist(
        string $event,
        Invoice $invoice,
        ?string $idempotencyKey = null,
    ): ?WebhookDelivery {
        if (! config('webhooks.enabled')) {
            return null;
        }

        $merchant = $invoice->merchant;

        if (! $merchant?->webhook_url || ! $merchant?->webhook_secret) {
            return null;
        }

        $payload = $this->payloadFactory->make($event, $invoice);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $idempotencyKey ??= "invoice:{$invoice->id}:event:{$event}";

        try {
            return WebhookDelivery::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'merchant_id' => $merchant->id,
                    'invoice_id' => $invoice->id,
                    'event' => $event,
                    'url' => $merchant->webhook_url,
                    'payload' => $payload,
                    'signature' => $this->signature->sign($payloadJson, $merchant->webhook_secret),
                    'attempts' => 0,
                    'next_retry_at' => null,
                    'status' => 'pending',
                    'last_error' => null,
                    'delivered_at' => null,
                ],
            );
        } catch (QueryException $e) {
            $existing = WebhookDelivery::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    public function dispatchAfterCommit(WebhookDelivery $delivery): void
    {
        if ($delivery->status !== 'pending') {
            return;
        }

        $deliveryId = $delivery->id;
        DB::afterCommit(static function () use ($deliveryId): void {
            DeliverWebhookJob::dispatch($deliveryId);
        });
    }
}
