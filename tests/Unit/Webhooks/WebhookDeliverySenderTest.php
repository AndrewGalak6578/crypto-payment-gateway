<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliverySender;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class WebhookDeliverySenderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsDomainData;

    public function test_sender_marks_delivery_as_delivered_on_successful_http_response(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        config()->set('webhooks.timeout_sec', 3);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid']);

        $payload = ['event' => 'invoice.paid', 'invoice' => ['id' => $invoice->id]];
        $signature = app(WebhookSignature::class)->sign(json_encode($payload), (string) $merchant->webhook_secret);

        $delivery = WebhookDelivery::query()->create([
            'invoice_id' => $invoice->id,
            'event' => 'invoice.paid',
            'url' => (string) $merchant->webhook_url,
            'payload' => $payload,
            'signature' => $signature,
            'attempts' => 0,
            'status' => 'pending',
        ]);

        app(WebhookDeliverySender::class)->send($delivery->id);

        $fresh = $delivery->fresh();
        self::assertSame('delivered', $fresh->status);
        self::assertSame(1, $fresh->attempts);
        self::assertNotNull($fresh->delivered_at);
    }

    public function test_sender_schedules_retry_on_http_error_then_marks_failed_at_limit(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['error' => 'fail'], 500)]);

        config()->set('webhooks.timeout_sec', 3);
        config()->set('webhook.retries.max_attempts', 2);
        config()->set('webhook.retries.backoff_sec', [10, 20]);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid']);

        $payload = ['event' => 'invoice.paid', 'invoice' => ['id' => $invoice->id]];
        $signature = app(WebhookSignature::class)->sign(json_encode($payload), (string) $merchant->webhook_secret);

        $delivery = WebhookDelivery::query()->create([
            'invoice_id' => $invoice->id,
            'event' => 'invoice.paid',
            'url' => (string) $merchant->webhook_url,
            'payload' => $payload,
            'signature' => $signature,
            'attempts' => 0,
            'status' => 'pending',
        ]);

        $sender = app(WebhookDeliverySender::class);
        $sender->send($delivery->id);

        $afterFirstFail = $delivery->fresh();
        self::assertSame('pending', $afterFirstFail->status);
        self::assertSame(1, $afterFirstFail->attempts);
        self::assertNotNull($afterFirstFail->next_retry_at);

        $afterFirstFail->next_retry_at = now('UTC')->subSecond();
        $afterFirstFail->save();

        $sender->send($delivery->id);

        $afterSecondFail = $delivery->fresh();
        self::assertSame('failed', $afterSecondFail->status);
        self::assertSame(2, $afterSecondFail->attempts);
        self::assertNull($afterSecondFail->next_retry_at);
    }
}
