<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MerchantBalance;
use App\Models\SuperWallet;
use App\Models\WebhookDelivery;
use App\Services\CoinBasedLogic\MockRpc;
use App\Services\InvoiceForwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\Support\FakeCoinRpc;
use Tests\TestCase;

final class InvoiceForwarderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsDomainData;

    public function test_forward_sends_to_wallet_with_fee_deduction_and_emits_webhook(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.min_coin.btc', 0.00001);
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc();
        $fakeRpc->nextTxid = 'tx_forward_1';
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 1.5]);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'wallet' => 'bcrt1qdestinationwallet1',
            'fee_rate' => 1.2,
        ]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'received_conf_coin' => 0.01,
            'forwarded_coin' => 0,
            'forward_status' => 'none',
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();

        self::assertSame('done', $fresh->forward_status);
        self::assertSame('0.00985000', (string) $fresh->forwarded_coin);
        self::assertSame(['tx_forward_1'], $fresh->forward_txids);
        self::assertNotNull($fresh->last_forwarded_at);

        self::assertCount(1, $fakeRpc->sendCalls);
        self::assertEqualsWithDelta(0.00985, $fakeRpc->sendCalls[0]['amount'], 0.00000001);

        $forwardedWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.forwarded')->first();
        self::assertNotNull($forwardedWebhook);
    }

    public function test_forward_without_wallet_credits_merchant_balance_and_emits_webhook(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);

        $merchant = $this->createMerchant(['fee_percent' => 2.0]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'received_conf_coin' => 0.5,
            'forward_status' => 'none',
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();
        self::assertSame('done', $fresh->forward_status);
        self::assertSame('0.01000000', (string) $fresh->fee_coin);
        self::assertSame('0.49000000', (string) $fresh->merchant_payout_coin);

        $balance = MerchantBalance::query()
            ->where('merchant_id', $merchant->id)
            ->where('coin', 'btc')
            ->first();

        self::assertNotNull($balance);
        self::assertSame('0.49000000', (string) $balance->amount);

        $forwardedWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.forwarded')->first();
        self::assertNotNull($forwardedWebhook);
    }

    public function test_forward_keeps_none_when_amount_below_minimum(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.min_coin.btc', 0.1);
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc();
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 0]);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'wallet' => 'bcrt1qdestinationwallet2',
            'fee_rate' => null,
        ]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'received_conf_coin' => 0.01,
            'forwarded_coin' => 0,
            'forward_status' => 'none',
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();

        self::assertSame('none', $fresh->forward_status);
        self::assertNull($fresh->forward_attempt_uuid);
        self::assertCount(0, $fakeRpc->sendCalls);

        $forwardedWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.forwarded')->first();
        self::assertNull($forwardedWebhook);
    }
}
