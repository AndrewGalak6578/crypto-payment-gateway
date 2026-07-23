<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Jobs\MonitorInvoiceJob;
use App\Models\AssetPolicy;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\CoinBasedLogic\MockRpc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsDomainData;
use Tests\Support\FakeCoinRpc;
use Tests\TestCase;

final class InvoiceApiTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_create_invoice_requires_bearer_token(): void
    {
        $response = $this->postJson('/api/v1/invoices', [
            'amount_usd' => 20,
            'coin' => 'btc',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_create_invoice_success_and_idempotency_by_external_id(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('payments.monitor.enabled', true);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(50000.0);
        });

        $merchant = $this->createMerchant();
        [, $plainToken] = $this->createApiKey($merchant, ['plain' => 'merchant_api_token_1']);

        $payload = [
            'external_id' => 'ext-1001',
            'amount_usd' => 20.00,
            'coin' => 'btc',
            'expires_minutes' => 30,
            'metadata' => ['order' => 123],
        ];

        $headers = ['Authorization' => 'Bearer '.$plainToken];

        $first = $this->postJson('/api/v1/invoices', $payload, $headers);
        $first->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.coin', 'BTC')
            ->assertJsonPath('data.external_id', 'ext-1001');

        Queue::assertPushed(MonitorInvoiceJob::class, 1);

        $second = $this->postJson('/api/v1/invoices', $payload, $headers);
        $second->assertCreated();

        self::assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'Expected idempotent invoice by external_id for the same merchant'
        );
    }

    public function test_create_invoice_uses_configured_app_url_for_hosted_url(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('payments.monitor.enabled', true);
        config()->set('app.url', 'https://settlane.tech');

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(50000.0);
        });

        $merchant = $this->createMerchant();
        [, $plainToken] = $this->createApiKey($merchant, ['plain' => 'merchant_api_token_hosted_url']);

        $response = $this
            ->postJson('/api/v1/invoices', [
                'external_id' => 'ext-hosted-url',
                'amount_usd' => 25.00,
                'coin' => 'btc',
                'expires_minutes' => 30,
            ], [
                'Authorization' => 'Bearer '.$plainToken,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.hosted_url',
                'https://settlane.tech/i/'.$response->json('data.public_id')
            );
    }

    public function test_create_invoice_without_coin_waits_for_asset_selection(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant();
        [, $plainToken] = $this->createApiKey($merchant, ['plain' => 'merchant_api_token_assetless']);

        $response = $this
            ->postJson('/api/v1/invoices', [
                'external_id' => 'ext-assetless',
                'amount_usd' => 42.00,
                'expires_minutes' => 30,
            ], [
                'Authorization' => 'Bearer '.$plainToken,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'awaiting_asset')
            ->assertJsonPath('data.coin', null)
            ->assertJsonPath('data.asset_key', null)
            ->assertJsonPath('data.network_key', null)
            ->assertJsonPath('data.pay_address', null)
            ->assertJsonPath('data.amount_coin', '0.00000000');

        Queue::assertNothingPushed();
    }

    public function test_create_invoice_rejects_asset_disabled_for_checkout_by_policy(): void
    {
        Queue::fake();

        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => false,
            'forwarding_enabled' => true,
        ]);

        $merchant = $this->createMerchant();
        [, $plainToken] = $this->createApiKey($merchant, ['plain' => 'merchant_api_token_policy']);

        $response = $this
            ->postJson('/api/v1/invoices', [
                'external_id' => 'ext-policy-blocked',
                'amount_usd' => 20.00,
                'coin' => 'btc',
            ], [
                'Authorization' => 'Bearer '.$plainToken,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.coin.0', 'Selected asset is not available for this checkout.');

        Queue::assertNothingPushed();
    }

    public function test_show_invoice_with_refresh_flag_returns_status_payload(): void
    {
        config()->set('coins.mode', 'mock');

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(50000.0);
        });

        $merchant = $this->createMerchant();
        [, $plainToken] = $this->createApiKey($merchant, ['plain' => 'merchant_api_token_2']);

        $invoice = $this->createInvoice($merchant, [
            'coin' => 'btc',
            'status' => 'pending',
            'received_conf_coin' => 0,
            'received_all_coin' => 0,
        ]);

        $response = $this->getJson('/api/v1/invoices/'.$invoice->id.'?refresh=1', [
            'Authorization' => 'Bearer '.$plainToken,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.status', 'pending');
    }
}
