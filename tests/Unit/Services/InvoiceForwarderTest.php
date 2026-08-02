<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\EvmGasTopUpServiceInterface;
use App\Contracts\EvmPayoutSenderInterface;
use App\Contracts\EvmTokenPayoutSenderInterface;
use App\Data\EvmGasTopUpOutcome;
use App\Data\EvmPayoutResult;
use App\Data\EvmSweepSource;
use App\Data\PreparedErc20Payout;
use App\Data\PreparedEvmPayout;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Jobs\ForwardInvoiceJob;
use App\Jobs\ReconcileSettlementAttemptJob;
use App\Models\AssetPolicy;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementAttempt;
use App\Models\MerchantSettlementEntry;
use App\Models\MerchantSettlementPreference;
use App\Models\PaymentAddress;
use App\Models\SuperWallet;
use App\Models\WebhookDelivery;
use App\Services\CoinBasedLogic\MockRpc;
use App\Services\InvoiceForwarder;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use App\Services\Settlement\SettlementAttemptReconciler;
use App\Services\Settlement\SettlementDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsDomainData;
use Tests\Support\FakeCoinRpc;
use Tests\TestCase;

final class InvoiceForwarderTest extends TestCase
{
    use BuildsDomainData {
        createInvoice as createDomainInvoice;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('forwarding.allow_platform_wallet_fallback', true);
    }

    public function test_duplicate_forward_job_execution_creates_one_active_attempt_and_one_send(): void
    {
        Queue::fake();
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.assets.btc.min', 0);
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);
        $merchant = $this->createMerchant(['fee_percent' => '0']);
        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qduplicatejobdestination',
            'fee_rate' => null,
        ]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => '0.500000000000000000',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        $forwarder = app(InvoiceForwarder::class);

        (new ForwardInvoiceJob($invoice->id))->handle($forwarder);
        (new ForwardInvoiceJob($invoice->id))->handle($forwarder);

        self::assertCount(1, $fakeRpc->sendCalls);
        self::assertSame(
            1,
            MerchantSettlementAttempt::query()->where('invoice_id', $invoice->id)->count(),
        );
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
    }

    public function test_txid_return_stays_broadcasted_until_confirmation_then_completes_once(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.assets.btc.min', 0.00001);
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc;
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

        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $fresh->forward_status);
        self::assertSame('0.000000000000000000', (string) $fresh->forwarded_coin);
        self::assertNull($fresh->forward_txids);
        self::assertNull($fresh->last_forwarded_at);

        self::assertCount(1, $fakeRpc->sendCalls);
        self::assertEqualsWithDelta(0.00985, $fakeRpc->sendCalls[0]['amount'], 0.00000001);

        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->sole();
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTED, $attempt->state);
        self::assertSame(MerchantSettlementAttempt::TRANSFER_UTXO, $attempt->transfer_type);
        self::assertSame('0.000150000000000000', (string) $attempt->fee_coin_snapshot);
        self::assertSame('0.009850000000000000', (string) $attempt->merchant_payout_coin_snapshot);
        self::assertSame('tx_forward_1', $attempt->txid);
        self::assertNotNull($fresh->settlement_snapshot_locked_at);
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);
        self::assertDatabaseMissing('webhook_deliveries', [
            'invoice_id' => $invoice->id,
            'event' => 'invoice.forwarded',
        ]);

        $fakeRpc->walletTransactions['tx_forward_1']['confirmations'] = 1;
        app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);
        app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);

        $fresh = $invoice->fresh();
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $fresh->forward_status);
        self::assertSame('0.009850000000000000', (string) $fresh->forwarded_coin);
        self::assertSame(['tx_forward_1'], $fresh->forward_txids);
        self::assertNotNull($fresh->last_forwarded_at);

        $entry = MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame(MerchantSettlementEntry::TYPE_FORWARD_SENT, $entry->type);
        self::assertSame(MerchantSettlementEntry::STATUS_COMPLETED, $entry->status);
        self::assertSame('0.009850000000000000', (string) $entry->amount_coin);
        self::assertSame('tx_forward_1', $entry->txid);
        self::assertSame($attempt->id, $entry->settlement_attempt_id);
        self::assertSame(MerchantSettlementAttempt::STATE_COMPLETED, $attempt->fresh()->state);

        $forwardedWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.forwarded')->first();
        self::assertNotNull($forwardedWebhook);
    }

    public function test_forward_uses_paid_settlement_snapshot_when_merchant_fee_changes(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.assets.btc.min', 0.00001);
        config()->set('webhooks.enabled', true);

        $fakeRpc = new FakeCoinRpc;
        $fakeRpc->nextTxid = 'tx_forward_snapshot';
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 1.5]);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'wallet' => 'bcrt1qdestinationwalletsnapshot',
            'fee_rate' => 1.2,
        ]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'received_conf_coin' => 0.01,
            'merchant_payout_coin' => 0.00985,
            'forwarded_coin' => 0,
            'forward_status' => 'none',
        ]);

        $merchant->forceFill(['fee_percent' => 10.0])->save();

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();

        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $fresh->forward_status);
        self::assertSame('0.000000000000000000', (string) $fresh->forwarded_coin);
        self::assertCount(1, $fakeRpc->sendCalls);
        self::assertEqualsWithDelta(0.00985, $fakeRpc->sendCalls[0]['amount'], 0.00000001);
    }

    public function test_forward_without_wallet_is_held_without_internal_credit_or_forwarded_webhook(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', true);

        $merchant = $this->createMerchant(['fee_percent' => 2.0]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'received_conf_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => 0.49,
            'fee_usd' => 100.00,
            'merchant_payout_usd' => 4900.00,
            'forward_status' => 'none',
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();
        self::assertSame(Invoice::FORWARD_STATUS_HELD, $fresh->forward_status);
        self::assertSame('0.010000000000000000', (string) $fresh->fee_coin);
        self::assertSame('0.490000000000000000', (string) $fresh->merchant_payout_coin);

        self::assertDatabaseMissing('merchant_balances', [
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
        ]);

        $entry = MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame(MerchantSettlementEntry::TYPE_FORWARD_HELD, $entry->type);
        self::assertSame(MerchantSettlementEntry::STATUS_DEFERRED, $entry->status);
        self::assertSame('0.490000000000000000', (string) $entry->amount_coin);
        self::assertSame('destination_wallet_missing', $entry->error_message);
        self::assertNull($entry->txid);

        $forwardedWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.forwarded')->first();
        self::assertNull($forwardedWebhook);
    }

    public function test_internal_balance_policy_credits_balance_even_when_wallet_exists(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);

        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 2.0]);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qdestinationwalletinternal',
            'fee_rate' => 1.2,
        ]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => 0.49,
            'fee_usd' => 100.00,
            'merchant_payout_usd' => 4900.00,
            'forward_status' => 'none',
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();
        self::assertSame('done', $fresh->forward_status);
        self::assertCount(0, $fakeRpc->sendCalls);

        $balance = MerchantBalance::query()
            ->where('merchant_id', $merchant->id)
            ->where('coin', 'btc')
            ->first();

        self::assertNotNull($balance);
        self::assertSame('0.490000000000000000', (string) $balance->amount);

        $entry = MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->first();
        self::assertNotNull($entry);
        self::assertSame(MerchantSettlementEntry::TYPE_INTERNAL_CREDIT, $entry->type);
    }

    public function test_forward_holds_when_amount_is_below_minimum(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.assets.btc.min', 0.1);
        config()->set('webhooks.enabled', true);

        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_THRESHOLD,
        ]);

        $fakeRpc = new FakeCoinRpc;
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

        self::assertSame(Invoice::FORWARD_STATUS_HELD, $fresh->forward_status);
        self::assertNull($fresh->forward_attempt_uuid);
        self::assertCount(0, $fakeRpc->sendCalls);
        $entry = MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->first();
        self::assertNotNull($entry);
        self::assertSame(MerchantSettlementEntry::TYPE_FORWARD_HELD, $entry->type);
        self::assertSame('below_threshold', $entry->error_message);

        $forwardedWebhook = WebhookDelivery::query()->where('invoice_id', $invoice->id)->where('event', 'invoice.forwarded')->first();
        self::assertNull($forwardedWebhook);
    }

    public function test_merchant_preference_changes_affect_new_evaluations_but_do_not_release_existing_hold(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);
        $merchant = $this->createMerchant(['fee_percent' => '0']);
        SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qmerchantpreferencedestination',
        ]);
        $preference = MerchantSettlementPreference::query()->create([
            'merchant_id' => $merchant->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'requested_mode' => AssetPolicy::MODE_THRESHOLD,
            'requested_minimum_invoice_payout' => '0.10000000',
            'revision' => 1,
        ]);
        $heldInvoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => '0.01000000',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        $forwarder = app(InvoiceForwarder::class);
        $forwarder->forward($heldInvoice->id);

        self::assertSame(Invoice::FORWARD_STATUS_HELD, $heldInvoice->fresh()->forward_status);
        self::assertSame('below_threshold', MerchantSettlementEntry::query()->where('invoice_id', $heldInvoice->id)->sole()->error_message);
        self::assertCount(0, $fakeRpc->sendCalls);

        $preference->update([
            'requested_mode' => AssetPolicy::MODE_IMMEDIATE,
            'requested_minimum_invoice_payout' => null,
            'revision' => 2,
        ]);

        $forwarder->forward($heldInvoice->id);
        self::assertSame(Invoice::FORWARD_STATUS_HELD, $heldInvoice->fresh()->forward_status);
        self::assertSame(1, MerchantSettlementEntry::query()->where('invoice_id', $heldInvoice->id)->count());
        self::assertCount(0, $fakeRpc->sendCalls);

        $newInvoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => '0.01000000',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        $forwarder->forward($newInvoice->id);

        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $newInvoice->fresh()->forward_status);
        self::assertCount(1, $fakeRpc->sendCalls);
        $attempt = MerchantSettlementAttempt::query()->where('invoice_id', $newInvoice->id)->sole();
        self::assertSame(AssetPolicy::MODE_IMMEDIATE, $attempt->metadata['policy_snapshot']['requested']['mode']);
        self::assertSame(AssetPolicy::MODE_IMMEDIATE, $attempt->metadata['policy_snapshot']['effective']['mode']);
    }

    public function test_disabled_and_manual_policies_record_non_retryable_holds(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qpolicyholddestination',
            'fee_rate' => null,
        ]);

        $cases = [
            [
                'mode' => AssetPolicy::MODE_MANUAL,
                'forwarding_enabled' => true,
                'expected_status' => Invoice::FORWARD_STATUS_MANUAL,
                'expected_reason' => 'manual_settlement_required',
            ],
            [
                'mode' => AssetPolicy::MODE_IMMEDIATE,
                'forwarding_enabled' => false,
                'expected_status' => Invoice::FORWARD_STATUS_HELD,
                'expected_reason' => 'forwarding_disabled_by_policy',
            ],
        ];

        foreach ($cases as $index => $case) {
            AssetPolicy::query()->delete();
            AssetPolicy::query()->create([
                'asset_key' => 'btc',
                'network_key' => 'bitcoin',
                'asset_enabled' => true,
                'checkout_enabled' => true,
                'forwarding_enabled' => $case['forwarding_enabled'],
                'settlement_mode' => $case['mode'],
            ]);

            $merchant = $this->createMerchant([
                'name' => "Policy Hold Merchant {$index}",
                'fee_percent' => 0,
            ]);
            $invoice = $this->createInvoice($merchant, [
                'status' => 'paid',
                'coin' => 'btc',
                'asset_key' => 'btc',
                'network_key' => 'bitcoin',
                'received_conf_coin' => 0.01,
                'forwarded_coin' => 0,
                'forward_status' => Invoice::FORWARD_STATUS_NONE,
            ]);

            app(InvoiceForwarder::class)->forward($invoice->id);

            self::assertSame($case['expected_status'], $invoice->fresh()->forward_status);
            self::assertDatabaseMissing('merchant_balances', [
                'merchant_id' => $merchant->id,
                'coin' => 'btc',
            ]);

            $entry = MerchantSettlementEntry::query()
                ->where('invoice_id', $invoice->id)
                ->sole();

            self::assertSame(MerchantSettlementEntry::TYPE_FORWARD_HELD, $entry->type);
            self::assertSame(MerchantSettlementEntry::STATUS_DEFERRED, $entry->status);
            self::assertSame($case['expected_reason'], $entry->error_message);
            self::assertSame($case['mode'] === AssetPolicy::MODE_MANUAL ? AssetPolicy::MODE_MANUAL : AssetPolicy::MODE_DISABLED, $entry->metadata['settlement_mode']);
            self::assertFalse($entry->metadata['forwarding_allowed']);
        }

        self::assertCount(0, $fakeRpc->sendCalls);
    }

    public function test_erc20_default_threshold_holds_below_minimum_without_gas_topup(): void
    {
        Queue::fake();

        config()->set('webhooks.enabled', false);
        config()->set('queue.default', 'database');
        config()->set('forwarding.assets.eth_usdt_local.min', 100.0);

        $merchant = $this->createMerchant(['fee_percent' => 10.0]);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'wallet' => '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            'fee_rate' => null,
        ]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'pay_address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'received_conf_coin' => 100.000000,
            'forwarded_coin' => 0,
            'forward_status' => 'none',
        ]);

        $this->mock(EvmGasTopUpServiceInterface::class, function ($mock): void {
            $mock->shouldNotReceive('ensureTopUpForErc20Transfer');
        });

        $this->mock(EvmTokenPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareToken');
            $mock->shouldNotReceive('broadcastToken');
        });

        $this->mock(EvmPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareNative');
            $mock->shouldNotReceive('broadcastNative');
        });

        app(InvoiceForwarder::class)->forward($invoice->id);
        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();

        self::assertSame(Invoice::FORWARD_STATUS_HELD, $fresh->forward_status);
        self::assertNull($fresh->forward_attempt_uuid);
        self::assertNull($fresh->forwarding_coin);
        self::assertNull($fresh->forwarding_started_at);
        self::assertSame('10.000000000000000000', (string) $fresh->fee_coin);
        self::assertSame('90.000000000000000000', (string) $fresh->merchant_payout_coin);

        $entry = MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->first();
        self::assertNotNull($entry);
        self::assertSame(MerchantSettlementEntry::TYPE_FORWARD_HELD, $entry->type);
        self::assertSame(MerchantSettlementEntry::STATUS_DEFERRED, $entry->status);
        self::assertSame('below_threshold', $entry->error_message);
        self::assertSame(AssetPolicy::MODE_THRESHOLD, $entry->metadata['settlement_mode']);
        self::assertSame('below_threshold', $entry->metadata['reason']);
        self::assertEquals(100.0, $entry->metadata['min_sweep_amount']);
        self::assertNull($entry->metadata['max_gas_cost']);
        self::assertEquals(90.0, $entry->metadata['remaining_amount']);
        self::assertFalse($entry->metadata['forwarding_allowed']);
        self::assertSame(1, MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->count());

        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }

    public function test_erc20_forward_is_deferred_after_gas_topup_submission(): void
    {
        Queue::fake();

        config()->set('webhooks.enabled', false);
        config()->set('queue.default', 'database');

        AssetPolicy::query()->create([
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
            'min_sweep_amount' => 0,
        ]);

        $merchant = $this->createMerchant(['fee_percent' => 0.0]);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'wallet' => '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            'fee_rate' => null,
        ]);

        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'pay_address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'received_conf_coin' => 25.000000,
            'forwarded_coin' => 0,
            'forward_status' => 'none',
        ]);

        PaymentAddress::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'network_key' => 'evm_local',
            'asset_key' => 'eth_usdt_local',
            'address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'family' => 'evm',
            'address_type' => 'deposit',
            'strategy' => 'hd_derived',
            'status' => 'assigned',
            'derivation_path' => "m/44'/60'/0'/0/0",
            'derivation_index' => 0,
            'key_ref' => 'anvil:default',
            'issued_at' => now('UTC'),
            'assigned_at' => now('UTC'),
            'meta' => [],
        ]);

        $this->mock(EvmGasTopUpServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('ensureTopUpForErc20Transfer')
                ->once()
                ->andReturn(new EvmGasTopUpOutcome(
                    status: 'funded',
                    requiresDeferredPayout: true,
                    txHash: '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    fundedAmountWei: '150000000000000',
                    gasStationAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
                    retryAfterSeconds: 10,
                ));
        });

        $this->mock(EvmTokenPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareToken');
            $mock->shouldNotReceive('broadcastToken');
        });

        $this->mock(EvmPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareNative');
            $mock->shouldNotReceive('broadcastNative');
        });

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();

        self::assertSame(Invoice::FORWARD_STATUS_FAILED, $fresh->forward_status);
        self::assertNull($fresh->forward_attempt_uuid);
        self::assertNull($fresh->forwarding_coin);
        self::assertNull($fresh->forwarding_started_at);

        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->sole();
        self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $attempt->state);
        self::assertTrue($attempt->retry_safe);
        self::assertDatabaseMissing('merchant_settlement_entries', [
            'invoice_id' => $invoice->id,
        ]);

        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }

    public function test_immediate_erc20_persists_prepared_transfer_before_broadcast_and_waits_for_confirmation(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', false);
        config()->set('queue.default', 'database');

        AssetPolicy::query()->create([
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
        ]);
        $merchant = $this->createMerchant(['fee_percent' => '0']);
        $wallet = SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'wallet' => '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
        ]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'pay_address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'received_conf_coin' => '25.000000',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        PaymentAddress::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'network_key' => 'evm_local',
            'asset_key' => 'eth_usdt_local',
            'address' => $invoice->pay_address,
            'family' => 'evm',
            'address_type' => 'deposit',
            'strategy' => 'hd_derived',
            'status' => 'assigned',
            'derivation_path' => "m/44'/60'/0'/0/0",
            'derivation_index' => 0,
            'key_ref' => 'anvil:default',
            'issued_at' => now('UTC'),
            'assigned_at' => now('UTC'),
            'meta' => [],
        ]);
        $source = new EvmSweepSource(
            networkKey: 'evm_local',
            address: strtolower($invoice->pay_address),
            keyRef: 'anvil:default',
            derivationPath: "m/44'/60'/0'/0/0",
            derivationIndex: 0,
        );
        $contract = strtolower((string) config('assets.eth_usdt_local.contract_address'));
        $amountAtomic = '25000000';
        $calldata = '0xa9059cbb'
            .str_pad(substr(strtolower($wallet->wallet), 2), 64, '0', STR_PAD_LEFT)
            .str_pad(dechex((int) $amountAtomic), 64, '0', STR_PAD_LEFT);
        $prepared = new PreparedErc20Payout(
            networkKey: 'evm_local',
            assetKey: 'eth_usdt_local',
            source: $source,
            contractAddress: $contract,
            destinationAddress: strtolower($wallet->wallet),
            amountDecimal: '25.000000',
            amountAtomic: $amountAtomic,
            calldata: $calldata,
            nonce: 9,
            chainId: 31337,
            broadcastBlockNumber: 120,
            transaction: [
                'from' => strtolower($source->address),
                'to' => $contract,
                'value' => '0x0',
                'data' => $calldata,
                'nonce' => '0x9',
            ],
        );

        $this->mock(EvmGasTopUpServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('ensureTopUpForErc20Transfer')
                ->once()
                ->andReturn(new EvmGasTopUpOutcome('sufficient', false));
        });
        $this->mock(EvmTokenPayoutSenderInterface::class, function ($mock) use ($prepared): void {
            $mock->shouldReceive('prepareToken')->once()->andReturn($prepared);
            $mock->shouldReceive('broadcastToken')->once()->andReturn(new EvmPayoutResult(
                txHash: '0x'.str_repeat('b', 64),
                fromAddress: strtolower($prepared->source->address),
                toAddress: $prepared->destinationAddress,
                amountDecimal: $prepared->amountDecimal,
                nonce: $prepared->nonce,
            ));
        });
        $this->mock(EvmPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareNative');
            $mock->shouldNotReceive('broadcastNative');
        });

        app(InvoiceForwarder::class)->forward($invoice->id);

        $attempt = MerchantSettlementAttempt::query()->where('invoice_id', $invoice->id)->sole();
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTED, $attempt->state);
        self::assertSame(MerchantSettlementAttempt::TRANSFER_ERC20, $attempt->transfer_type);
        self::assertSame('9', $attempt->nonce);
        self::assertSame('31337', $attempt->chain_id);
        self::assertSame(120, $attempt->broadcast_block_number);
        self::assertSame($contract, $attempt->token_contract);
        self::assertSame($amountAtomic, $attempt->atomic_amount);
        self::assertSame($calldata, $attempt->calldata);
        self::assertSame(hash('sha256', strtolower($calldata)), $attempt->calldata_fingerprint);
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);
        Queue::assertPushed(ReconcileSettlementAttemptJob::class);
    }

    public function test_erc20_prebroadcast_gas_service_failure_is_retry_safe(): void
    {
        config()->set('webhooks.enabled', false);

        AssetPolicy::query()->create([
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
            'min_sweep_amount' => 0,
        ]);

        $merchant = $this->createMerchant(['fee_percent' => 0.0]);
        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'wallet' => '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            'fee_rate' => null,
        ]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'pay_address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'received_conf_coin' => 25.0,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        PaymentAddress::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'network_key' => 'evm_local',
            'asset_key' => 'eth_usdt_local',
            'address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'family' => 'evm',
            'address_type' => 'deposit',
            'strategy' => 'hd_derived',
            'status' => 'assigned',
            'derivation_path' => "m/44'/60'/0'/0/0",
            'derivation_index' => 0,
            'key_ref' => 'anvil:default',
            'issued_at' => now('UTC'),
            'assigned_at' => now('UTC'),
            'meta' => [],
        ]);

        $this->mock(EvmGasTopUpServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('ensureTopUpForErc20Transfer')
                ->twice()
                ->andThrow(new \RuntimeException('Gas sponsor RPC timeout after broadcast.'));
        });
        $this->mock(EvmTokenPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareToken');
            $mock->shouldNotReceive('broadcastToken');
        });
        $this->mock(EvmPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareNative');
            $mock->shouldNotReceive('broadcastNative');
        });

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('Expected simulated gas sponsorship timeout.');
        } catch (\RuntimeException $e) {
            self::assertSame('Gas sponsor RPC timeout after broadcast.', $e->getMessage());
        }

        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->sole();
        self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $attempt->state);
        self::assertTrue($attempt->retry_safe);
        self::assertSame(
            '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            $attempt->source_address,
        );
        self::assertNotNull($attempt->token_contract);
        self::assertNotNull($attempt->transaction_fingerprint);
        self::assertSame(Invoice::FORWARD_STATUS_FAILED, $invoice->fresh()->forward_status);

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('Expected simulated pre-broadcast gas service failure on retry.');
        } catch (\RuntimeException $e) {
            self::assertSame('Gas sponsor RPC timeout after broadcast.', $e->getMessage());
        }

        self::assertSame(2, MerchantSettlementAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertDatabaseMissing('merchant_settlement_entries', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_utxo_timeout_after_broadcast_is_quarantined_and_never_sent_twice(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.assets.btc.min', 0);
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $fakeRpc->throwAfterBroadcast = true;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 2.0]);
        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qtimeoutdestination',
            'fee_rate' => 1.0,
        ]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'forwarded_coin' => 0,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('Expected simulated post-broadcast timeout.');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated timeout after broadcast.', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        self::assertSame('paid', $fresh->status);
        self::assertSame(Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION, $fresh->forward_status);
        self::assertNotNull($fresh->forward_attempt_uuid);
        self::assertNotNull($fresh->settlement_snapshot_locked_at);
        self::assertCount(1, $fakeRpc->sendCalls);
        self::assertNotNull($fakeRpc->sendCalls[0]['reference']);

        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->sole();
        self::assertSame(MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION, $attempt->state);
        self::assertFalse($attempt->retry_safe);
        self::assertSame('rpc-wallet:bitcoin', $attempt->source_reference);
        self::assertNotNull($attempt->broadcasting_at);
        self::assertNull($attempt->txid);
        self::assertDatabaseMissing('merchant_settlement_entries', [
            'invoice_id' => $invoice->id,
        ]);

        $fakeRpc->throwAfterBroadcast = false;
        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertCount(1, $fakeRpc->sendCalls);
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
        self::assertSame($fakeRpc->nextTxid, $attempt->fresh()->txid);
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTED, $attempt->fresh()->state);

        $fakeRpc->walletTransactions[$fakeRpc->nextTxid]['confirmations'] = 1;
        app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);

        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
        self::assertCount(1, $fakeRpc->sendCalls);
    }

    public function test_redelivered_job_reconciles_interrupted_broadcast_before_rpc_send(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.assets.btc.min', 0);
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 2.0]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => 0.49,
            'settlement_snapshot_locked_at' => now('UTC'),
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        $attempt = app(MerchantSettlementAttemptManager::class)->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'utxo',
            transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
            destinationAddress: 'bcrt1qinterruptedbroadcast',
        );
        self::assertNotNull($attempt);

        app(MerchantSettlementAttemptManager::class)->markBroadcasting(
            attemptId: $attempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
        );

        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertSame(
            MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION,
            $attempt->fresh()->state,
        );
        self::assertSame('utxo_reference_not_found', $attempt->fresh()->error_message);
        self::assertSame(
            Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION,
            $invoice->fresh()->forward_status,
        );
        self::assertSame('paid', $invoice->fresh()->status);
        self::assertCount(0, $fakeRpc->sendCalls);
    }

    public function test_evm_native_timeout_after_broadcast_preserves_nonce_and_never_sends_twice(): void
    {
        config()->set('webhooks.enabled', false);

        AssetPolicy::query()->create([
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
            'min_sweep_amount' => 0,
        ]);

        $merchant = $this->createMerchant(['fee_percent' => 1.0]);
        $wallet = SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'eth_local',
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'wallet' => '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            'fee_rate' => null,
        ]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_local',
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'pay_address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'received_conf_coin' => 1.0,
            'forwarded_coin' => 0,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        PaymentAddress::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'network_key' => 'evm_local',
            'asset_key' => 'eth_local',
            'address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            'family' => 'evm',
            'address_type' => 'deposit',
            'strategy' => 'hd_derived',
            'status' => 'assigned',
            'derivation_path' => "m/44'/60'/0'/0/0",
            'derivation_index' => 0,
            'key_ref' => 'anvil:default',
            'issued_at' => now('UTC'),
            'assigned_at' => now('UTC'),
            'meta' => [],
        ]);

        $source = new EvmSweepSource(
            networkKey: 'evm_local',
            address: '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            keyRef: 'anvil:default',
            derivationPath: "m/44'/60'/0'/0/0",
            derivationIndex: 0,
        );
        $prepared = new PreparedEvmPayout(
            networkKey: 'evm_local',
            assetKey: 'eth_local',
            source: $source,
            destinationAddress: strtolower($wallet->wallet),
            amountDecimal: '0.990000000000000000',
            amountAtomic: '990000000000000000',
            nonce: 7,
            chainId: 31337,
            broadcastBlockNumber: 100,
            transaction: [
                'from' => strtolower($source->address),
                'to' => strtolower($wallet->wallet),
                'value' => '0x0dbd2fc137a30000',
                'nonce' => '0x7',
            ],
        );

        $this->mock(EvmPayoutSenderInterface::class, function ($mock) use ($prepared): void {
            $mock->shouldReceive('prepareNative')->once()->andReturn($prepared);
            $mock->shouldReceive('broadcastNative')
                ->once()
                ->andThrow(new \RuntimeException('EVM RPC timeout after broadcast.'));
        });
        $this->mock(EvmGasTopUpServiceInterface::class, function ($mock): void {
            $mock->shouldNotReceive('ensureTopUpForErc20Transfer');
        });
        $this->mock(EvmTokenPayoutSenderInterface::class, function ($mock): void {
            $mock->shouldNotReceive('prepareToken');
            $mock->shouldNotReceive('broadcastToken');
        });

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('Expected simulated EVM post-broadcast timeout.');
        } catch (\RuntimeException $e) {
            self::assertSame('EVM RPC timeout after broadcast.', $e->getMessage());
        }

        $attempt = MerchantSettlementAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->sole();
        self::assertSame(MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION, $attempt->state);
        self::assertSame(strtolower($source->address), $attempt->source_address);
        self::assertSame('7', $attempt->nonce);
        self::assertSame($prepared->fingerprint(), $attempt->transaction_fingerprint);
        self::assertNull($attempt->txid);
        self::assertSame(Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION, $invoice->fresh()->forward_status);

        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertSame(1, MerchantSettlementAttempt::query()->where('invoice_id', $invoice->id)->count());
        self::assertDatabaseMissing('merchant_settlement_entries', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_policy_held_and_manual_invoices_ignore_stale_forward_jobs(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);

        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
            'min_sweep_amount' => 0,
        ]);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qstalepolicyjobdestination',
            'fee_rate' => null,
        ]);

        $merchant = $this->createMerchant(['fee_percent' => 0]);

        foreach ([
            Invoice::FORWARD_STATUS_HELD,
            Invoice::FORWARD_STATUS_MANUAL,
            Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION,
        ] as $forwardStatus) {
            $invoice = $this->createInvoice($merchant, [
                'status' => 'paid',
                'received_conf_coin' => 0.5,
                'merchant_payout_coin' => 0.5,
                'forward_status' => $forwardStatus,
            ]);

            app(InvoiceForwarder::class)->forward($invoice->id);

            self::assertSame($forwardStatus, $invoice->fresh()->forward_status);
            self::assertDatabaseMissing('merchant_settlement_entries', [
                'invoice_id' => $invoice->id,
            ]);
        }

        self::assertCount(0, $fakeRpc->sendCalls);
    }

    public function test_legacy_failed_invoice_without_attempt_is_quarantined_without_sending(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 0]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'forward_status' => Invoice::FORWARD_STATUS_FAILED,
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertSame(
            Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION,
            $invoice->fresh()->forward_status,
        );
        self::assertCount(0, $fakeRpc->sendCalls);
        self::assertDatabaseMissing('merchant_settlement_attempts', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_legacy_invoice_with_payout_txid_but_no_durable_audit_is_quarantined(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);
        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);
        $merchant = $this->createMerchant(['fee_percent' => '0']);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => '0.50000000',
            'forwarded_coin' => '0',
            'forward_txids' => ['legacy-payout-txid'],
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertSame(
            Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION,
            $invoice->fresh()->forward_status,
        );
        self::assertCount(0, $fakeRpc->sendCalls);
        self::assertDatabaseMissing('merchant_settlement_attempts', ['invoice_id' => $invoice->id]);
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);
    }

    public function test_internal_balance_policy_credits_only_unsettled_net_after_partial_forward(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);

        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        $merchant = $this->createMerchant(['fee_percent' => 25]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => 0.49,
            'forwarded_coin' => 0.2,
            'forward_status' => Invoice::FORWARD_STATUS_PARTIAL,
        ]);

        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => 0.2,
            'fee_coin' => 0.01,
            'amount_usd' => 2000,
            'destination_wallet' => 'bcrt1qpreviouspartialforward',
            'txid' => 'tx_previous_partial',
            'idempotency_key' => "invoice:{$invoice->id}:backfill:forward",
            'metadata' => ['backfilled' => true],
            'occurred_at' => now('UTC'),
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $fresh->forward_status);
        self::assertSame('0.200000000000000000', (string) $fresh->forwarded_coin);

        $balance = MerchantBalance::query()
            ->where('merchant_id', $merchant->id)
            ->where('coin', 'btc')
            ->sole();
        self::assertSame('0.290000000000000000', (string) $balance->amount);

        $credit = MerchantSettlementEntry::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', MerchantSettlementEntry::TYPE_INTERNAL_CREDIT)
            ->sole();
        self::assertSame('0.290000000000000000', (string) $credit->amount_coin);
        self::assertSame('internal_balance_only', $credit->metadata['reason']);
        self::assertCount(0, $fakeRpc->sendCalls);
    }

    public function test_completed_ledger_entries_prevent_stale_invoice_double_settlement(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $this->app->instance(MockRpc::class, $fakeRpc);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qstaleledgerdestination',
            'fee_rate' => null,
        ]);

        $merchant = $this->createMerchant(['fee_percent' => 2]);
        $forwardedInvoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => 0.49,
            'forwarded_coin' => 0,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        $creditedInvoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => 0.49,
            'forwarded_coin' => 0,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $forwardedInvoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => 0.49,
            'fee_coin' => 0.01,
            'amount_usd' => 4900,
            'destination_wallet' => 'bcrt1qhistoricforward',
            'txid' => 'tx_historic_forward',
            'idempotency_key' => "invoice:{$forwardedInvoice->id}:backfill:forward",
            'metadata' => ['backfilled' => true],
            'occurred_at' => now('UTC'),
        ]);
        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $creditedInvoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => 0.49,
            'fee_coin' => 0.01,
            'amount_usd' => 4900,
            'destination_wallet' => null,
            'txid' => null,
            'idempotency_key' => "invoice:{$creditedInvoice->id}:backfill:internal-credit",
            'metadata' => ['backfilled' => true],
            'occurred_at' => now('UTC'),
        ]);

        app(InvoiceForwarder::class)->forward($forwardedInvoice->id);

        self::assertSame(Invoice::FORWARD_STATUS_DONE, $forwardedInvoice->fresh()->forward_status);
        self::assertSame('0.490000000000000000', (string) $forwardedInvoice->fresh()->forwarded_coin);
        self::assertCount(0, $fakeRpc->sendCalls);
        self::assertDatabaseMissing('merchant_balances', [
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
        ]);
        self::assertSame(1, MerchantSettlementEntry::query()->where('invoice_id', $forwardedInvoice->id)->count());
        self::assertSame(1, MerchantSettlementEntry::query()->where('invoice_id', $creditedInvoice->id)->count());

        try {
            app(InvoiceForwarder::class)->forward($creditedInvoice->id);
            self::fail('A retryable invoice with pre-existing internal-credit evidence must conflict.');
        } catch (CustodyIdempotencyConflictException) {
            self::assertSame(Invoice::FORWARD_STATUS_NONE, $creditedInvoice->fresh()->forward_status);
            self::assertDatabaseMissing('merchant_balances', [
                'merchant_id' => $merchant->id,
                'coin' => 'btc',
            ]);
            self::assertCount(0, $fakeRpc->sendCalls);
        }
    }

    public function test_legacy_paid_invoice_without_locked_snapshot_is_quarantined(): void
    {
        config()->set('coins.mode', 'mock');
        config()->set('forwarding.assets.btc.min', 0);
        config()->set('webhooks.enabled', false);

        $fakeRpc = new FakeCoinRpc;
        $fakeRpc->nextTxid = 'tx_fee_snapshot';
        $this->app->instance(MockRpc::class, $fakeRpc);

        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qfeesnapshotdestination',
            'fee_rate' => null,
        ]);

        $merchant = $this->createMerchant(['fee_percent' => 10]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => 0.5,
            'fee_coin' => 0.01,
            'merchant_payout_coin' => null,
            'settlement_snapshot_locked_at' => null,
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertCount(0, $fakeRpc->sendCalls);
        self::assertSame(Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION, $invoice->fresh()->forward_status);
        self::assertDatabaseMissing('merchant_settlement_attempts', ['invoice_id' => $invoice->id]);
    }

    /**
     * Unit fixtures model the paid transition by supplying its immutable snapshot.
     * Passing settlement_snapshot_locked_at explicitly keeps legacy/quarantine tests possible.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createInvoice(Merchant $merchant, array $overrides = []): Invoice
    {
        if (
            ($overrides['status'] ?? 'pending') === 'paid'
            && ! array_key_exists('settlement_snapshot_locked_at', $overrides)
        ) {
            $assetKey = (string) ($overrides['asset_key'] ?? $overrides['coin'] ?? 'btc');
            $decimal = app(SettlementDecimal::class);
            $gross = $decimal->asset($overrides['received_conf_coin'] ?? '0', $assetKey);
            $fee = array_key_exists('fee_coin', $overrides) && $overrides['fee_coin'] !== null
                ? $decimal->asset($overrides['fee_coin'], $assetKey)
                : null;
            $payout = array_key_exists('merchant_payout_coin', $overrides)
                && $overrides['merchant_payout_coin'] !== null
                    ? $decimal->asset($overrides['merchant_payout_coin'], $assetKey)
                    : null;

            if ($fee === null && $payout === null) {
                $fee = $decimal->percentage(
                    $gross,
                    $merchant->getRawOriginal('fee_percent') ?? '0',
                    $assetKey,
                );
                $payout = $decimal->positiveOrZero($gross->minus($fee), $assetKey);
            } elseif ($fee === null) {
                $fee = $decimal->positiveOrZero($gross->minus($payout), $assetKey);
            } elseif ($payout === null) {
                $payout = $decimal->positiveOrZero($gross->minus($fee), $assetKey);
            }

            $overrides['fee_coin'] = (string) $fee;
            $overrides['merchant_payout_coin'] = (string) $payout;
            $overrides['settlement_snapshot_locked_at'] = now('UTC');
        }

        return $this->createDomainInvoice($merchant, $overrides);
    }
}
