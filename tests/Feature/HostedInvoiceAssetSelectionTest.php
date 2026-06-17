<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\MonitorInvoiceJob;
use App\Models\PaymentAddress;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\CoinBasedLogic\MockRpc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsDomainData;
use Tests\Support\FakeCoinRpc;
use Tests\TestCase;

final class HostedInvoiceAssetSelectionTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_public_invoice_asset_selection_fixates_invoice_once(): void
    {
        Queue::fake();
        config()->set('coins.mode', 'mock');

        $this->app->instance(MockRpc::class, new FakeCoinRpc);
        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->once()->with('btc')->andReturn(50000.0);
        });

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'awaiting_asset',
            'coin' => null,
            'asset_key' => null,
            'network_key' => null,
            'pay_address' => null,
            'amount_coin' => 0,
            'expected_usd' => 100.00,
            'rate_usd' => 0,
            'monitor_until' => null,
        ]);

        $response = $this
            ->withSession(['_token' => 'select-token'])
            ->postJson('/i/'.$invoice->public_id.'/select-asset', [
                'asset_key' => 'btc',
            ], [
                'X-CSRF-TOKEN' => 'select-token',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.coin', 'BTC')
            ->assertJsonPath('data.asset_key', 'btc')
            ->assertJsonPath('data.network_key', 'bitcoin')
            ->assertJsonPath('data.amount_coin', '0.00200000')
            ->assertJsonPath('data.rate_usd', '50000.00000000');

        $fresh = $invoice->fresh();
        self::assertSame('pending', $fresh->status);
        self::assertSame('btc', $fresh->asset_key);
        self::assertNotNull($fresh->pay_address);
        self::assertSame(1, PaymentAddress::query()->where('invoice_id', $fresh->id)->count());
        Queue::assertPushed(MonitorInvoiceJob::class, 1);
    }

    public function test_reselecting_asset_returns_existing_fixated_invoice_without_new_address(): void
    {
        Queue::fake();
        config()->set('coins.mode', 'mock');

        $this->app->instance(MockRpc::class, new FakeCoinRpc);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'pending',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'pay_address' => 'mock_addr_existing',
            'amount_coin' => 0.002,
            'expected_usd' => 100.00,
            'rate_usd' => 50000.00,
        ]);

        PaymentAddress::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'network_key' => 'bitcoin',
            'asset_key' => 'btc',
            'address' => 'mock_addr_existing',
            'family' => 'utxo',
            'address_type' => 'deposit',
            'strategy' => 'utxo_rpc',
            'status' => 'assigned',
            'issued_at' => now('UTC'),
            'assigned_at' => now('UTC'),
            'meta' => [],
        ]);

        $response = $this
            ->withSession(['_token' => 'select-token'])
            ->postJson('/i/'.$invoice->public_id.'/select-asset', [
                'asset_key' => 'ltc',
            ], [
                'X-CSRF-TOKEN' => 'select-token',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.asset_key', 'btc')
            ->assertJsonPath('data.pay_address', 'mock_addr_existing');

        self::assertSame(1, PaymentAddress::query()->where('invoice_id', $invoice->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_expired_invoice_cannot_select_asset(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'awaiting_asset',
            'coin' => null,
            'asset_key' => null,
            'network_key' => null,
            'pay_address' => null,
            'amount_coin' => 0,
            'rate_usd' => 0,
            'expires_at' => now('UTC')->subMinute(),
        ]);

        $response = $this
            ->withSession(['_token' => 'select-token'])
            ->postJson('/i/'.$invoice->public_id.'/select-asset', [
                'asset_key' => 'btc',
            ], [
                'X-CSRF-TOKEN' => 'select-token',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        self::assertSame('expired', $invoice->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_unknown_asset_is_rejected_without_defaulting_to_dash(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'awaiting_asset',
            'coin' => null,
            'asset_key' => null,
            'network_key' => null,
            'pay_address' => null,
            'amount_coin' => 0,
            'rate_usd' => 0,
        ]);

        $response = $this
            ->withSession(['_token' => 'select-token'])
            ->postJson('/i/'.$invoice->public_id.'/select-asset', [
                'asset_key' => 'not-a-real-asset',
            ], [
                'X-CSRF-TOKEN' => 'select-token',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        $fresh = $invoice->fresh();
        self::assertSame('awaiting_asset', $fresh->status);
        self::assertNull($fresh->coin);
        self::assertNull($fresh->asset_key);
        Queue::assertNothingPushed();
    }
}
