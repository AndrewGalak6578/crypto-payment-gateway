<?php
declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\Invoice;
use App\Models\WebhookDelivery;

final class EnqueueInvoiceWebhook
{
    public function __construct(
        private readonly InvoiceWebhookPayloadFactory $payloadFactory,
        private readonly WebhookSignature $signature,
    )
    {}

    public function enqueue(string $event, Invoice $invoice): ?WebhookDelivery
    {
        if (!config('webhooks.enabled')) {
            return null;
        }

        $merchant = $invoice->merchant;

        if (!$merchant?->webhook_url || !$merchant?->webhook_secret) {
            return null;
        }

        $payload = $this->payloadFactory->make($event, $invoice);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $delivery = WebhookDelivery::query()->create([
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
        ]);

        DeliverWebhookJob::dispatch($delivery->id);

        return $delivery;
    }
}
