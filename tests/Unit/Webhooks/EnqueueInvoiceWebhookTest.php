<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class EnqueueInvoiceWebhookTest extends TestCase
{
    use RefreshDatabase;
    use BuildsDomainData;

    public function test_enqueue_returns_null_when_webhooks_disabled(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', false);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant);

        $delivery = app(EnqueueInvoiceWebhook::class)->enqueue('invoice.paid', $invoice->fresh(['merchant']));

        self::assertNull($delivery);
        Queue::assertNothingPushed();
    }

    public function test_enqueue_creates_delivery_and_dispatches_job(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', true);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid']);

        $delivery = app(EnqueueInvoiceWebhook::class)->enqueue('invoice.paid', $invoice->fresh(['merchant']));

        self::assertNotNull($delivery);
        self::assertSame('pending', $delivery->status);

        $saved = WebhookDelivery::query()->find($delivery->id);
        self::assertNotNull($saved);
        self::assertSame('invoice.paid', $saved->event);

        Queue::assertPushed(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($delivery): bool {
            return $job->deliveryId === $delivery->id;
        });
    }
}
