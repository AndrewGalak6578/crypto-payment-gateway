<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use Illuminate\Validation\ValidationException;

final class SendMerchantTestWebhook
{
    public function __construct(private readonly WebhookSignature $signature)
    {
    }

    public function send(Merchant $merchant): WebhookDelivery
    {
        if (! $merchant->webhook_url || ! $merchant->webhook_secret) {
            throw ValidationException::withMessages([
                'webhook_url' => 'Configure webhook URL and secret before sending a test signal.',
            ]);
        }

        $payload = [
            'event' => 'merchant.webhook_test',
            'test' => true,
            'merchant' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
            ],
            'created_at' => now('UTC')->toIso8601String(),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $delivery = WebhookDelivery::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => null,
            'event' => 'merchant.webhook_test',
            'url' => $merchant->webhook_url,
            'payload' => $payload,
            'signature' => $this->signature->sign((string) $payloadJson, $merchant->webhook_secret),
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
