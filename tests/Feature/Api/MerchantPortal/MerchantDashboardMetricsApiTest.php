<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantBalance;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Models\SuperWallet;
use App\Models\WebhookDelivery;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MerchantDashboardMetricsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
        Cache::flush();
    }

    public function test_dashboard_returns_financial_metrics_and_health_snapshot(): void
    {
        $merchant = $this->createMerchant([
            'webhook_url' => 'https://merchant.example/webhook',
            'webhook_secret' => 'secret',
        ]);
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-dashboard@example.test');

        $currentPaid = $this->createInvoice($merchant, [
            'public_id' => 'INV-CURRENT-1',
            'status' => 'paid',
            'expected_usd' => '100.00',
            'paid_usd' => '100.00',
            'merchant_payout_usd' => '96.00',
            'rate_usd' => '2.00',
            'paid_at' => now()->startOfMonth()->addDays(3),
            'created_at' => now()->startOfMonth()->addDays(3),
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-CURRENT-2',
            'status' => 'paid',
            'expected_usd' => '50.00',
            'paid_usd' => '50.00',
            'merchant_payout_usd' => '48.00',
            'rate_usd' => '2.00',
            'paid_at' => now()->startOfMonth()->addDays(5),
            'created_at' => now()->startOfMonth()->addDays(5),
            'last_forwarded_at' => now()->startOfMonth()->addDays(6),
            'forward_status' => 'done',
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-PREV',
            'status' => 'paid',
            'expected_usd' => '60.00',
            'paid_usd' => '60.00',
            'merchant_payout_usd' => '58.00',
            'rate_usd' => '2.00',
            'paid_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3),
            'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3),
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-AWAITING',
            'status' => 'pending',
            'expected_usd' => '25.00',
            'amount_coin' => '12.50000000',
            'rate_usd' => '2.00',
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-CONFIRMING',
            'status' => 'fixated',
            'expected_usd' => '40.00',
            'amount_coin' => '20.00000000',
            'rate_usd' => '2.00',
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-SHORT',
            'status' => 'pending',
            'expected_usd' => '20.00',
            'amount_coin' => '10.00000000',
            'received_all_coin' => '5.00000000',
            'rate_usd' => '2.00',
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-EXPIRED-FUNDS',
            'status' => 'expired',
            'expected_usd' => '10.00',
            'amount_coin' => '5.00000000',
            'received_all_coin' => '2.00000000',
            'rate_usd' => '2.00',
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-FORWARD-FAILED',
            'status' => 'paid',
            'expected_usd' => '15.00',
            'paid_usd' => '15.00',
            'rate_usd' => '2.00',
            'forward_status' => 'failed',
            'paid_at' => now()->startOfMonth()->addDays(4),
            'created_at' => now()->startOfMonth()->addDays(4),
        ]);

        $this->createWebhookDelivery($currentPaid, ['status' => 'failed']);
        MerchantBalance::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'DASH',
            'amount' => '10.00000000',
        ]);
        MerchantApiKey::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Default key',
            'token_hash' => hash('sha256', 'test-token'),
        ]);
        SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'DASH',
            'asset_key' => 'dash',
            'network_key' => 'dash',
            'wallet' => 'XforwardingWallet',
        ]);

        $this->actingAs($owner, 'merchant');

        $response = $this->getJson('/api/merchant/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.metrics.received_month_usd', '159.00')
            ->assertJsonPath('data.metrics.paid_count', 3)
            ->assertJsonPath('data.metrics.in_flight_usd', '85.00')
            ->assertJsonPath('data.metrics.awaiting_count', 2)
            ->assertJsonPath('data.metrics.confirming_count', 1)
            ->assertJsonPath('data.metrics.underpaid_count', 1)
            ->assertJsonPath('data.metrics.underpaid_missing_usd', '10.00')
            ->assertJsonPath('data.metrics.forwarded_month_usd', '48.00')
            ->assertJsonPath('data.metrics.forwarded_count', 1)
            ->assertJsonPath('data.metrics.wallet_estimate_usd', '20.00')
            ->assertJsonPath('data.wallet_balances.0.estimated_usd', '20.00')
            ->assertJsonPath('data.integration_health.api_keys_ready', true)
            ->assertJsonPath('data.integration_health.webhook_ready', true)
            ->assertJsonPath('data.integration_health.settlement_wallet_ready', true)
            ->assertJsonPath('data.attention.3.type', 'webhook_failed');
    }

    public function test_dashboard_hides_webhook_attention_details_without_webhook_read(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid']);
        $this->createWebhookDelivery($invoice, ['status' => 'failed']);
        $viewer = $this->createMerchantUser($merchant, 'merchant.viewer', 'viewer-dashboard@example.test');

        $this->actingAs($viewer, 'merchant');

        $response = $this->getJson('/api/merchant/dashboard');

        $response->assertOk()
            ->assertJsonMissingPath('data.attention.3');

        $this->assertNotContains('webhook_failed', collect($response->json('data.attention'))->pluck('type')->all());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createMerchant(array $overrides = []): Merchant
    {
        return Merchant::query()->create(array_merge([
            'name' => 'Dashboard Merchant',
            'status' => 'active',
            'fee_percent' => 2.00,
        ], $overrides));
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createInvoice(Merchant $merchant, array $overrides = []): Invoice
    {
        $publicId = $overrides['public_id'] ?? 'INV-'.strtoupper(substr(md5(json_encode($overrides)), 0, 10));
        unset($overrides['public_id']);

        return Invoice::query()->create(array_merge([
            'merchant_id' => $merchant->id,
            'public_id' => $publicId,
            'external_id' => 'order-'.$publicId,
            'status' => 'pending',
            'coin' => 'DASH',
            'asset_key' => 'dash',
            'network_key' => 'dash',
            'pay_address' => 'XyMerchantAddress',
            'amount_coin' => '10.00000000',
            'expected_usd' => '10.00',
            'rate_usd' => '1.00',
            'received_conf_coin' => '0.00000000',
            'received_all_coin' => '0.00000000',
            'forward_status' => 'none',
            'expires_at' => now()->addHour(),
            'metadata' => ['order_id' => $publicId],
        ], $overrides));
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
