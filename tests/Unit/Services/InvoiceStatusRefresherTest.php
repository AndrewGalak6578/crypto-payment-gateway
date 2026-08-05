<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\ForwardInvoiceJob;
use App\Models\Invoice;
use App\Models\MerchantSettlementEntry;
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
    use BuildsDomainData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableForwardingForTests('invoice_status_refresher_existing_contract');
    }

    public function test_refresh_moves_pending_to_fixated_and_emits_webhook(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc;
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

        $fakeRpc = new FakeCoinRpc;
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
        self::assertEquals(99.50, (float) $fresh->paid_usd);
        self::assertEquals(1.49, (float) $fresh->fee_usd);
        self::assertEquals(98.01, (float) $fresh->merchant_payout_usd);
        self::assertEquals(0.00014925, (float) $fresh->fee_coin);
        self::assertEquals(0.00980075, (float) $fresh->merchant_payout_coin);
        self::assertNotNull($fresh->settlement_snapshot_locked_at);

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

        $fakeRpc = new FakeCoinRpc;
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

    public function test_refresh_does_not_dispatch_forward_for_policy_held_or_manual_statuses(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);
        config()->set('forwarding.enabled', true);

        $fakeRpc = new FakeCoinRpc;
        $fakeRpc->totals = [
            'confirmed' => 0.01,
            'unconfirmed' => 0.0,
            'all' => 0.01,
        ];
        $fakeRpc->txs = [[
            'txid' => 'tx_policy_held',
            'amount' => 0.01,
            'time' => now('UTC')->timestamp,
        ]];
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant();

        foreach ([
            Invoice::FORWARD_STATUS_HELD,
            Invoice::FORWARD_STATUS_MANUAL,
            Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION,
        ] as $forwardStatus) {
            $invoice = $this->createInvoice($merchant, [
                'status' => 'paid',
                'amount_coin' => 0.01,
                'received_conf_coin' => 0.01,
                'received_all_coin' => 0.01,
                'forwarded_coin' => 0,
                'forward_status' => $forwardStatus,
            ]);

            $fresh = app(InvoiceStatusRefresher::class)->refresh($invoice);
            self::assertSame($forwardStatus, $fresh->forward_status);
        }

        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }

    public function test_refresh_quarantines_legacy_failed_invoice_without_retry_safe_attempt(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);
        config()->set('forwarding.enabled', true);

        $fakeRpc = new FakeCoinRpc;
        $fakeRpc->totals = [
            'confirmed' => 0.5,
            'unconfirmed' => 0.0,
            'all' => 0.5,
        ];
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'received_all_coin' => 0.5,
            'forward_status' => Invoice::FORWARD_STATUS_FAILED,
        ]);

        $fresh = app(InvoiceStatusRefresher::class)->refresh($invoice);

        self::assertSame(Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION, $fresh->forward_status);
        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }

    public function test_refresh_quarantines_legacy_paid_invoice_without_locked_snapshot(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);
        config()->set('forwarding.enabled', true);

        $fakeRpc = new FakeCoinRpc;
        $fakeRpc->totals = [
            'confirmed' => 0.5,
            'unconfirmed' => 0.0,
            'all' => 0.5,
        ];
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'received_all_coin' => 0.5,
            'fee_coin' => 0.0075,
            'merchant_payout_coin' => 0.4925,
            'settlement_snapshot_locked_at' => null,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        $fresh = app(InvoiceStatusRefresher::class)->refresh($invoice);

        self::assertSame(Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION, $fresh->forward_status);
        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }

    public function test_refresh_marks_pending_invoice_as_expired_after_ttl(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc;
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

    public function test_refresh_does_not_dispatch_when_completed_ledger_covers_stale_invoice(): void
    {
        Queue::fake();

        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);
        config()->set('forwarding.enabled', true);

        $fakeRpc = new FakeCoinRpc;
        $fakeRpc->totals = [
            'confirmed' => 0.5,
            'unconfirmed' => 0.0,
            'all' => 0.5,
        ];
        $fakeRpc->txs = [[
            'txid' => 'tx_historic_refresh',
            'amount' => 0.5,
            'time' => now('UTC')->timestamp,
        ]];
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 2]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'received_all_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => 0.49,
            'forwarded_coin' => 0,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => 0.49,
            'fee_coin' => 0.01,
            'amount_usd' => 4900,
            'destination_wallet' => 'bcrt1qhistoricrefreshdestination',
            'txid' => 'tx_historic_refresh',
            'idempotency_key' => "invoice:{$invoice->id}:backfill:forward",
            'metadata' => ['backfilled' => true],
            'occurred_at' => now('UTC'),
        ]);

        $fresh = app(InvoiceStatusRefresher::class)->refresh($invoice);

        self::assertSame('0.490000000000000000', (string) $fresh->forwarded_coin);
        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }
}
