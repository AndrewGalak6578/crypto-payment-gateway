<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\ForwardInvoiceJob;
use App\Models\WebhookDelivery;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\CoinBasedLogic\MockRpc;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsDomainData;
use Tests\Support\FakeCoinRpc;
use Tests\TestCase;

final class InvoiceStatusRefresherTest extends TestCase
{
    use RefreshDatabase;
    use BuildsDomainData;

    public function test_refresh_moves_pending_to_fixated_and_emits_webhook(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc();
        $fakeRpc->totals = [
            'confirmed' => 0.0,
            'unconfirmed' => 0.01,
            'all' => 0.01,
        ];
        $fakeRpc->txs = [[
            'txid' => 'tx_fixated',
            'amount' => 0.01,
            'time' => now('UTC')->timestamp,
        ]];

        $this->app->instance(MockRpc::class, $fakeRpc);
        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(10000.0);
        });

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'pending',
            'amount_coin' => 0.01,
            'expected_usd' => 100.00,
            'expires_at' => now('UTC')->addHour(),
        ]);

        $fresh = app(InvoiceStatusRefresher::class)->refresh($invoice);

        self::assertSame('fixated', $fresh->status);
        self::assertNotNull($fresh->fixated_at);
        self::assertNotNull($fresh->first_txid);

        $event = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.fixated')->first();
        self::assertNotNull($event);
    }

    public function test_refresh_marks_paid_and_dispatches_forward_job(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);
        config()->set('forwarding.enabled', true);
        config()->set('payments.slippage.paid_coin_percent', 0.5);

        $fakeRpc = new FakeCoinRpc();
        $fakeRpc->totals = [
            'confirmed' => 0.00995,
            'unconfirmed' => 0.0,
            'all' => 0.00995,
        ];
        $fakeRpc->txs = [[
            'txid' => 'tx_paid',
            'amount' => 0.00995,
            'time' => now('UTC')->timestamp,
        ]];

        $this->app->instance(MockRpc::class, $fakeRpc);
        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(10000.0);
        });

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'fixated',
            'amount_coin' => 0.01,
            'received_conf_coin' => 0,
            'received_all_coin' => 0,
        ]);

        $fresh = app(InvoiceStatusRefresher::class)->refresh($invoice);

        self::assertSame('paid', $fresh->status);
        self::assertNotNull($fresh->paid_at);
        self::assertNotNull($fresh->paid_usd);

        $paidWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.paid')->first();
        self::assertNotNull($paidWebhook);

        Queue::assertPushed(ForwardInvoiceJob::class, function (ForwardInvoiceJob $job) use ($invoice): bool {
            return $job->invoiceId === $invoice->id;
        });
    }

    public function test_refresh_does_not_dispatch_forward_when_net_target_already_forwarded(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);
        config()->set('forwarding.enabled', true);

        $fakeRpc = new FakeCoinRpc();
        $fakeRpc->totals = [
            'confirmed' => 0.01,
            'unconfirmed' => 0.0,
            'all' => 0.01,
        ];
        $fakeRpc->txs = [[
            'txid' => 'tx_paid_done',
            'amount' => 0.01,
            'time' => now('UTC')->timestamp,
        ]];

        $this->app->instance(MockRpc::class, $fakeRpc);
        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(10000.0);
        });

        $merchant = $this->createMerchant(['fee_percent' => 10.0]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'amount_coin' => 0.01,
            'received_conf_coin' => 0.01,
            'received_all_coin' => 0.01,
            'forwarded_coin' => 0.009,
            'forward_status' => 'done',
        ]);

        app(InvoiceStatusRefresher::class)->refresh($invoice);

        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }

    public function test_refresh_marks_pending_invoice_as_expired_after_ttl(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc();
        $this->app->instance(MockRpc::class, $fakeRpc);

        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(10000.0);
        });

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'pending',
            'expires_at' => now('UTC')->subMinute(),
            'received_conf_coin' => 0,
            'received_all_coin' => 0,
        ]);

        $fresh = app(InvoiceStatusRefresher::class)->refresh($invoice);

        self::assertSame('expired', $fresh->status);

        $expiredWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.expired')->first();
        self::assertNotNull($expiredWebhook);
    }
}
