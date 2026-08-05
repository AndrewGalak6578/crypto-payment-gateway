<?php

declare(strict_types=1);

namespace Tests\Integration\RealChains;

use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Models\SuperWallet;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\InvoiceForwarder;
use App\Services\InvoiceStatusRefresher;
use App\Services\Settlement\SettlementAttemptReconciler;
use App\Support\Coin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class RealChainForwardingTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableForwardingForTests();
    }

    #[DataProvider('coinProvider')]
    public function test_paid_invoice_broadcasts_net_amount_without_completing_before_confirmation(string $coin): void
    {
        $this->skipUnlessRealRpcEnabled();

        config()->set('coins.mode', 'real');
        config()->set('forwarding.enabled', true);
        config()->set('webhooks.enabled', false);
        config()->set('payments.confirmations', 0);

        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(10000.0);
        });

        $rpc = Coin::rpc($coin);
        $balance = $rpc->getBalance();
        if ($balance <= 0.05) {
            $this->markTestSkipped("{$coin} wallet is not funded enough for real-chain test");
        }

        $merchant = $this->createMerchant([
            'fee_percent' => 10.0,
            'webhook_url' => null,
            'webhook_secret' => null,
        ]);

        $destination = $rpc->getNewAddress('dest:'.$coin.':'.uniqid('', true));

        SuperWallet::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'coin' => $coin],
            ['wallet' => $destination, 'fee_rate' => null]
        );

        $payAddress = $rpc->getNewAddress('inv:'.$coin.':'.uniqid('', true));
        $amount = match ($coin) {
            'ltc' => 0.002, // after 10% fee => 0.0018, above min_coin.ltc=0.001
            'dash' => 0.02,
            default => 0.001,
        };

        $invoice = $this->createInvoice($merchant, [
            'coin' => $coin,
            'status' => 'pending',
            'pay_address' => $payAddress,
            'amount_coin' => $amount,
            'received_conf_coin' => 0,
            'received_all_coin' => 0,
            'forwarded_coin' => 0,
            'forward_status' => 'none',
        ]);

        $rpc->sendToAddress($payAddress, $amount);

        $fresh = $this->refreshUntilPaid($invoice->id);
        app(InvoiceForwarder::class)->forward($invoice->id);
        $fresh = $fresh->fresh();

        self::assertSame('paid', $fresh->status);
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $fresh->forward_status);
        self::assertNull($fresh->last_forwarded_at);
        self::assertNull($fresh->forward_txids);

        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->sole();
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTED, $attempt->state);
        self::assertNotNull($attempt->txid);
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);

        $scale = $coin === 'dash' ? 3 : 8;
        $expectedNet = round($amount * 0.9, $scale);
        self::assertEqualsWithDelta($expectedNet, (float) $attempt->amount_coin, 1e-8);

        $destTotals = $rpc->getReceivedTotals($destination, 0);
        self::assertGreaterThanOrEqual($expectedNet, (float) ($destTotals['all'] ?? 0.0));

        $reconciler = app(SettlementAttemptReconciler::class);
        for ($i = 0; $i < 20 && $attempt->fresh()->state !== MerchantSettlementAttempt::STATE_COMPLETED; $i++) {
            $reconciler->reconcile($attempt->id, true);
            usleep(500000);
        }

        self::assertSame(MerchantSettlementAttempt::STATE_COMPLETED, $attempt->fresh()->state);
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
        self::assertDatabaseHas('merchant_settlement_entries', [
            'invoice_id' => $invoice->id,
            'settlement_attempt_id' => $attempt->id,
            'status' => 'completed',
        ]);
    }

    public function test_paid_invoice_is_held_when_merchant_wallet_is_missing(): void
    {
        $this->skipUnlessRealRpcEnabled();

        config()->set('coins.mode', 'real');
        config()->set('forwarding.enabled', true);
        config()->set('webhooks.enabled', false);
        config()->set('payments.confirmations', 0);

        $this->mock(CoinRate::class, function ($mock): void {
            $mock->shouldReceive('usd')->andReturn(10000.0);
        });

        $coin = 'btc';
        $rpc = Coin::rpc($coin);
        if ($rpc->getBalance() <= 0.02) {
            $this->markTestSkipped('btc wallet is not funded enough for missing-wallet hold test');
        }

        SuperWallet::query()->where('coin', $coin)->delete();

        $merchant = $this->createMerchant([
            'fee_percent' => 10.0,
            'webhook_url' => null,
            'webhook_secret' => null,
        ]);

        $payAddress = $rpc->getNewAddress('inv:fallback:'.uniqid('', true));
        $amount = 0.001;

        $invoice = $this->createInvoice($merchant, [
            'coin' => $coin,
            'status' => 'pending',
            'pay_address' => $payAddress,
            'amount_coin' => $amount,
            'received_conf_coin' => 0,
            'received_all_coin' => 0,
            'forwarded_coin' => 0,
            'forward_status' => 'none',
        ]);

        $rpc->sendToAddress($payAddress, $amount);

        $fresh = $this->refreshUntilPaid($invoice->id);
        app(InvoiceForwarder::class)->forward($invoice->id);
        $fresh = $fresh->fresh();

        self::assertSame('paid', $fresh->status);
        self::assertSame(Invoice::FORWARD_STATUS_HELD, $fresh->forward_status);
        self::assertNull($fresh->last_forwarded_at);
        self::assertDatabaseMissing('merchant_balances', [
            'merchant_id' => $merchant->id,
            'coin' => $coin,
        ]);
        self::assertDatabaseHas('merchant_settlement_entries', [
            'invoice_id' => $invoice->id,
            'type' => 'forward_held',
            'error_message' => 'destination_wallet_missing',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function coinProvider(): array
    {
        return [
            'btc' => ['btc'],
            'ltc' => ['ltc'],
            'dash' => ['dash'],
        ];
    }

    private function skipUnlessRealRpcEnabled(): void
    {
        if (! (bool) env('RUN_REAL_RPC_TESTS', false)) {
            $this->markTestSkipped('Set RUN_REAL_RPC_TESTS=true to run real chain integration tests.');
        }
    }

    private function refreshUntilPaid(int $invoiceId, int $attempts = 15): Invoice
    {
        $refresher = app(InvoiceStatusRefresher::class);
        $last = Invoice::query()->findOrFail($invoiceId);

        for ($i = 0; $i < $attempts; $i++) {
            $last = $refresher->refresh($last);
            if ($last->status === 'paid') {
                return $last;
            }

            usleep(300000);
            $last = $last->fresh();
        }

        return $last;
    }
}
