<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\EnqueueInvoiceWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class EnqueueInvoiceWebhookTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

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

    public function test_delivery_is_idempotent_and_dispatch_waits_for_commit(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', true);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid'])->fresh(['merchant']);
        DB::beginTransaction();

        $first = app(EnqueueInvoiceWebhook::class)->enqueue('invoice.forwarded', $invoice);
        $second = app(EnqueueInvoiceWebhook::class)->enqueue('invoice.forwarded', $invoice);

        self::assertNotNull($first);
        self::assertSame($first->id, $second?->id);
        self::assertSame(1, WebhookDelivery::query()->where('event', 'invoice.forwarded')->count());
        Queue::assertNothingPushed();

        DB::commit();

        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_pending_delivery_command_recovers_a_lost_dispatch(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', true);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid'])->fresh(['merchant']);
        $delivery = app(EnqueueInvoiceWebhook::class)->persist('invoice.forwarded', $invoice);

        self::assertNotNull($delivery);
        Queue::assertNothingPushed();

        $this->artisan('webhooks:dispatch-pending', ['--limit' => 10])->assertSuccessful();

        Queue::assertPushed(
            DeliverWebhookJob::class,
            fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->id,
        );
    }

    public function test_pending_delivery_command_recovers_stale_delivering_row(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', true);
        config()->set('webhooks.delivering_stale_seconds', 60);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid'])->fresh(['merchant']);
        $delivery = app(EnqueueInvoiceWebhook::class)->persist('invoice.forwarded', $invoice);
        self::assertNotNull($delivery);
        $delivery->forceFill([
            'status' => 'delivering',
            'updated_at' => now('UTC')->subMinutes(5),
        ])->saveQuietly();

        $this->artisan('webhooks:dispatch-pending', ['--limit' => 10])->assertSuccessful();

        self::assertSame('pending', $delivery->fresh()->status);
        self::assertStringContainsString('HTTP outcome may be ambiguous', (string) $delivery->fresh()->last_error);
        Queue::assertPushed(
            DeliverWebhookJob::class,
            fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->id,
        );
    }
}
