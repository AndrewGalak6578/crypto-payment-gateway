<?php

declare(strict_types=1);

namespace Tests\Feature\Api\AdminPortal;

use App\Jobs\DeliverWebhookJob;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminWebhookDeliveryRetryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_retry_resets_failed_delivery_to_pending_and_dispatches_job(): void
    {
        Queue::fake();

        $merchant = Merchant::query()->create([
            'name' => 'Merchant A',
            'status' => 'active',
            'fee_percent' => 2.00,
        ]);
        $invoice = Invoice::query()->create([
            'merchant_id' => $merchant->id,
            'public_id' => 'INV-ADMIN-RETRY',
            'external_id' => 'order-admin',
            'status' => 'paid',
            'coin' => 'DASH',
            'asset_key' => 'dash',
            'network_key' => 'dash',
            'pay_address' => 'XyMerchantAddress',
            'amount_coin' => '10.00000000',
            'expected_usd' => '10.00',
            'rate_usd' => '1.00',
            'received_conf_coin' => '10.00000000',
            'received_all_coin' => '10.00000000',
            'forward_status' => 'none',
            'expires_at' => now()->addHour(),
        ]);
        $delivery = WebhookDelivery::query()->create([
            'invoice_id' => $invoice->id,
            'event' => 'invoice.paid',
            'url' => 'https://merchant.example/webhook',
            'payload' => ['invoice_id' => $invoice->public_id],
            'signature' => 'test-signature',
            'attempts' => 6,
            'next_retry_at' => now()->addHour(),
            'status' => 'failed',
            'last_error' => 'HTTP 500',
            'delivered_at' => null,
        ]);

        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->postJson("/api/admin/webhook-deliveries/{$delivery->id}/retry");

        $response->assertOk()
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.queued', true);

        $this->assertDatabaseHas('webhook_deliveries', [
            'id' => $delivery->id,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->id);
    }

    private function makeAdmin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-webhook-retry@example.test',
            'password' => 'password123',
            'role' => AdminUser::ROLE_SUPER_ADMIN,
            'status' => AdminUser::STATUS_ACTIVE,
        ]);
    }
}
