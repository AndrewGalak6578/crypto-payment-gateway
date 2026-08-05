<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AssetPolicy;
use App\Models\Invoice;
use App\Models\MerchantSettlementEntry;
use App\Services\InvoiceForwarder;
use App\Services\Settlement\MerchantSettlementLedger;
use App\Services\Settlement\SettlementAmountCalculator;
use App\Services\SettlementPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class SettlementPrecisionTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableForwardingForTests();
    }

    public function test_eighteen_decimal_fee_and_internal_credit_survive_without_precision_loss(): void
    {
        config()->set('webhooks.enabled', false);
        AssetPolicy::query()->create([
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);
        $merchant = $this->createMerchant(['fee_percent' => '0.1000']);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_local',
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'received_conf_coin' => '1.000000000000000001',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);

        self::assertSame(
            '0.999000000000000001',
            app(SettlementAmountCalculator::class)->targetNetCoin($invoice),
        );
        $invoice->forceFill([
            'fee_coin' => '0.001000000000000000',
            'merchant_payout_coin' => '0.999000000000000001',
            'settlement_snapshot_locked_at' => now('UTC'),
        ])->save();

        app(InvoiceForwarder::class)->forward($invoice->id);

        $fresh = $invoice->fresh();
        self::assertSame('0.001000000000000000', (string) $fresh->fee_coin);
        self::assertSame('0.999000000000000001', (string) $fresh->merchant_payout_coin);
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $fresh->forward_status);
        self::assertDatabaseHas('merchant_balances', [
            'merchant_id' => $merchant->id,
            'coin' => 'eth_local',
            'amount' => '0.999000000000000001',
        ]);
    }

    public function test_erc20_threshold_comparison_uses_exact_asset_scale(): void
    {
        AssetPolicy::query()->create([
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_THRESHOLD,
            'min_sweep_amount' => '100.000000',
        ]);
        $merchant = $this->createMerchant(['fee_percent' => '0']);

        $below = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'received_conf_coin' => '99.999999',
            'fee_coin' => '0',
            'merchant_payout_coin' => '99.999999',
            'settlement_snapshot_locked_at' => now('UTC'),
        ]);
        $exact = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'received_conf_coin' => '100.000000',
            'fee_coin' => '0',
            'merchant_payout_coin' => '100.000000',
            'settlement_snapshot_locked_at' => now('UTC'),
        ]);

        self::assertSame('below_threshold', app(SettlementPolicyResolver::class)->resolveForInvoice($below)->reason);
        self::assertSame('forwarding_allowed', app(SettlementPolicyResolver::class)->resolveForInvoice($exact)->reason);

        $policy = AssetPolicy::query()
            ->where('asset_key', 'eth_usdt_local')
            ->where('network_key', 'evm_local')
            ->sole();
        $policy->min_sweep_amount = '100.0000005';
        $policy->save();

        $decision = app(SettlementPolicyResolver::class)->resolveForInvoice($exact);
        self::assertSame('100.000001', $decision->minSweepAmount);
        self::assertSame('below_threshold', $decision->reason);
    }

    public function test_partial_forwarding_and_ledger_accumulation_are_exact(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_local',
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'received_conf_coin' => '1.000000000000000003',
            'fee_coin' => '0',
            'merchant_payout_coin' => '1.000000000000000003',
            'forwarded_coin' => '0.300000000000000001',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forward_status' => Invoice::FORWARD_STATUS_PARTIAL,
        ]);

        foreach ([
            '0.100000000000000001',
            '0.100000000000000002',
            '0.100000000000000003',
        ] as $index => $amount) {
            MerchantSettlementEntry::query()->create([
                'merchant_id' => $merchant->id,
                'invoice_id' => $invoice->id,
                'asset_key' => 'eth_local',
                'network_key' => 'evm_local',
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => MerchantSettlementEntry::STATUS_COMPLETED,
                'amount_coin' => $amount,
                'fee_coin' => '0',
                'txid' => "precision-tx-{$index}",
                'idempotency_key' => "invoice:{$invoice->id}:precision:{$index}",
                'occurred_at' => now('UTC'),
            ]);
        }

        self::assertSame(
            '0.300000000000000006',
            app(MerchantSettlementLedger::class)->completedForwardAmount($invoice),
        );
        self::assertSame(
            '0.699999999999999997',
            app(SettlementAmountCalculator::class)->remainingPayoutCoin($invoice),
        );
    }
}
