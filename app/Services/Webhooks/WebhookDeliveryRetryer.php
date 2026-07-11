<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;

final class WebhookDeliveryRetryer
{
    public function retry(WebhookDelivery $delivery): bool
    {
        if ($delivery->status === 'delivered') {
            return false;
        }

        $delivery->forceFill([
            'status' => 'pending',
            'next_retry_at' => null,
        ])->save();

        DeliverWebhookJob::dispatch($delivery->id);

        return true;
    }
}
