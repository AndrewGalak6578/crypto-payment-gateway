<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Jobs\DeliverWebhookJob;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Models\WebhookDelivery;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MerchantPaymentDetailWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_detail_payload_includes_webhook_deliveries_only_with_webhook_read_capability(): void
    {
        $merchant = $this->createMerchant('Merchant A');
        $invoice = $this->createInvoice($merchant);
        $delivery = $this->createWebhookDelivery($invoice);

        $analyst = $this->createMerchantUser($merchant, 'merchant.analyst', 'analyst@example.test');
        $this->actingAs($analyst, 'merchant');

        $readResponse = $this->getJson("/api/merchant/invoices/{$invoice->id}");

        $readResponse->assertOk()
            ->assertJsonPath('data.webhook_deliveries.0.id', $delivery->id)
            ->assertJsonPath('data.webhook_deliveries.0.event', 'invoice.paid');

        $viewer = $this->createMerchantUser($merchant, 'merchant.viewer', 'viewer@example.test');
        $this->actingAs($viewer, 'merchant');

        $viewerResponse = $this->getJson("/api/merchant/invoices/{$invoice->id}");

        $viewerResponse->assertOk()
            ->assertJsonMissingPath('data.webhook_deliveries');
    }

    public function test_detail_payload_can_be_loaded_by_public_id_with_merchant_scope(): void
    {
        $merchant = $this->createMerchant('Merchant A');
        $otherMerchant = $this->createMerchant('Merchant B');
        $invoice = $this->createInvoice($merchant);
        $otherInvoice = $this->createInvoice($otherMerchant);

        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-public-id@example.test');
        $this->actingAs($owner, 'merchant');

        $response = $this->getJson("/api/merchant/invoices/{$invoice->public_id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.public_id', $invoice->public_id);

        $this->getJson("/api/merchant/invoices/{$otherInvoice->public_id}")
            ->assertNotFound();
    }

    public function test_owner_can_retry_own_failed_webhook_delivery(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant('Merchant A');
        $invoice = $this->createInvoice($merchant);
        $delivery = $this->createWebhookDelivery($invoice, [
            'status' => 'failed',
            'attempts' => 6,
            'next_retry_at' => now()->addHour(),
            'last_error' => 'HTTP 500',
        ]);

        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner@example.test');
        $this->actingAs($owner, 'merchant');

        $response = $this->postJson("/api/merchant/webhook-deliveries/{$delivery->id}/retry");

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

    public function test_user_without_webhook_write_cannot_retry_delivery(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant('Merchant A');
        $invoice = $this->createInvoice($merchant);
        $delivery = $this->createWebhookDelivery($invoice, ['status' => 'failed']);

        $analyst = $this->createMerchantUser($merchant, 'merchant.analyst', 'analyst-no-write@example.test');
        $this->actingAs($analyst, 'merchant');

        $response = $this->postJson("/api/merchant/webhook-deliveries/{$delivery->id}/retry");

        $response->assertForbidden();

        $this->assertDatabaseHas('webhook_deliveries', [
            'id' => $delivery->id,
            'status' => 'failed',
        ]);
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_owner_cannot_retry_another_merchants_delivery(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant('Merchant A');
        $otherMerchant = $this->createMerchant('Merchant B');
        $otherInvoice = $this->createInvoice($otherMerchant);
        $delivery = $this->createWebhookDelivery($otherInvoice, ['status' => 'failed']);

        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-other@example.test');
        $this->actingAs($owner, 'merchant');

        $response = $this->postJson("/api/merchant/webhook-deliveries/{$delivery->id}/retry");

        $response->assertNotFound();

        $this->assertDatabaseHas('webhook_deliveries', [
            'id' => $delivery->id,
            'status' => 'failed',
        ]);
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    private function createMerchant(string $name): Merchant
    {
        return Merchant::query()->create([
            'name' => $name,
            'status' => 'active',
            'fee_percent' => 2.00,
        ]);
    }

    private function createMerchantUser(Merchant $merchant, string $roleSlug, string $email): MerchantUser
    {
        return MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => 'password123',
            'role_id' => (int) Role::query()->where('slug', $roleSlug)->value('id'),
            'status' => 'active',
        ]);
    }

    private function createInvoice(Merchant $merchant): Invoice
    {
        return Invoice::query()->create([
            'merchant_id' => $merchant->id,
            'public_id' => 'INV-'.strtoupper(substr(md5((string) $merchant->id), 0, 8)),
            'external_id' => 'order-'.$merchant->id,
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
            'metadata' => ['order_id' => 'A-100'],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createWebhookDelivery(Invoice $invoice, array $overrides = []): WebhookDelivery
    {
        return WebhookDelivery::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'event' => 'invoice.paid',
            'url' => 'https://merchant.example/webhook',
            'payload' => ['invoice_id' => $invoice->public_id],
            'signature' => 'test-signature',
            'attempts' => 1,
            'next_retry_at' => null,
            'status' => 'delivered',
            'last_error' => null,
            'delivered_at' => now(),
        ], $overrides));
    }
}
