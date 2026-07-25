<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AssetPolicy;
use App\Models\MerchantAssetPolicy;
use App\Services\SettlementPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class SettlementPolicyResolverTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_threshold_cannot_be_replaced_by_internal_credit_in_a_lower_admin_layer(): void
    {
        $merchant = $this->createMerchant();
        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'settlement_mode' => AssetPolicy::MODE_THRESHOLD,
            'min_sweep_amount' => '0.10000000',
        ]);
        MerchantAssetPolicy::query()->create([
            'merchant_id' => $merchant->id,
            'scope_key' => MerchantAssetPolicy::SCOPE_ALL,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => '0.05000000',
            'received_all_coin' => '0.05000000',
            'fee_coin' => '0.00000000',
            'merchant_payout_coin' => '0.05000000',
            'settlement_snapshot_locked_at' => now('UTC'),
        ]);

        $decision = app(SettlementPolicyResolver::class)->resolveForInvoice($invoice);

        self::assertSame(AssetPolicy::MODE_THRESHOLD, $decision->mode);
        self::assertSame('below_threshold', $decision->reason);
        self::assertTrue($decision->shouldHold());
        self::assertFalse($decision->shouldCreditInternalBalance());
        self::assertContains(
            'admin_mode_not_monotonic',
            array_column($decision->policySnapshot['inherited']['constraints'], 'code'),
        );
    }

    public function test_internal_credit_is_an_explicit_admin_branch_from_immediate_mode(): void
    {
        $merchant = $this->createMerchant();
        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
        ]);
        MerchantAssetPolicy::query()->create([
            'merchant_id' => $merchant->id,
            'scope_key' => MerchantAssetPolicy::SCOPE_ALL,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);

        $configuration = app(SettlementPolicyResolver::class)->resolveForMerchantAsset(
            $merchant,
            'btc',
            'bitcoin',
        );

        self::assertSame(AssetPolicy::MODE_INTERNAL_BALANCE_ONLY, $configuration->inheritedMode);
    }

    public function test_invalid_stored_admin_mode_fails_closed_and_cannot_be_reenabled_downstream(): void
    {
        $merchant = $this->createMerchant();
        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'settlement_mode' => 'unknown_accounting_path',
        ]);
        MerchantAssetPolicy::query()->create([
            'merchant_id' => $merchant->id,
            'scope_key' => MerchantAssetPolicy::scopeKey('btc', 'bitcoin'),
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
        ]);

        $configuration = app(SettlementPolicyResolver::class)->resolveForMerchantAsset(
            $merchant,
            'btc',
            'bitcoin',
        );

        self::assertSame(AssetPolicy::MODE_DISABLED, $configuration->inheritedMode);
        self::assertContains(
            'invalid_admin_settlement_mode',
            array_column($configuration->constraints, 'code'),
        );
    }

    public function test_accounting_paths_use_an_explicit_compatibility_matrix(): void
    {
        $resolver = app(SettlementPolicyResolver::class);
        $adminCases = [
            [AssetPolicy::MODE_IMMEDIATE, AssetPolicy::MODE_THRESHOLD, AssetPolicy::MODE_THRESHOLD],
            [AssetPolicy::MODE_THRESHOLD, AssetPolicy::MODE_INTERNAL_BALANCE_ONLY, AssetPolicy::MODE_THRESHOLD],
            [AssetPolicy::MODE_INTERNAL_BALANCE_ONLY, AssetPolicy::MODE_THRESHOLD, AssetPolicy::MODE_INTERNAL_BALANCE_ONLY],
            [AssetPolicy::MODE_MANUAL, AssetPolicy::MODE_INTERNAL_BALANCE_ONLY, AssetPolicy::MODE_MANUAL],
            [AssetPolicy::MODE_DISABLED, AssetPolicy::MODE_IMMEDIATE, AssetPolicy::MODE_DISABLED],
            [AssetPolicy::MODE_MANUAL, AssetPolicy::MODE_DISABLED, AssetPolicy::MODE_DISABLED],
        ];

        foreach ($adminCases as [$globalMode, $merchantAdminMode, $expectedMode]) {
            AssetPolicy::query()->delete();
            $merchant = $this->createMerchant();
            AssetPolicy::query()->create([
                'asset_key' => 'btc',
                'network_key' => 'bitcoin',
                'settlement_mode' => $globalMode,
            ]);
            MerchantAssetPolicy::query()->create([
                'merchant_id' => $merchant->id,
                'scope_key' => MerchantAssetPolicy::SCOPE_ALL,
                'settlement_mode' => $merchantAdminMode,
            ]);

            $configuration = $resolver->resolveForMerchantAsset($merchant, 'btc', 'bitcoin');
            self::assertSame(
                $expectedMode,
                $configuration->inheritedMode,
                "Unexpected admin composition for {$globalMode} -> {$merchantAdminMode}.",
            );
        }

        self::assertTrue($resolver->canMerchantRequestMode(
            AssetPolicy::MODE_IMMEDIATE,
            AssetPolicy::MODE_THRESHOLD,
        ));
        self::assertTrue($resolver->canMerchantRequestMode(
            AssetPolicy::MODE_THRESHOLD,
            AssetPolicy::MODE_DISABLED,
        ));
        self::assertFalse($resolver->canMerchantRequestMode(
            AssetPolicy::MODE_THRESHOLD,
            AssetPolicy::MODE_IMMEDIATE,
        ));
        self::assertFalse($resolver->canMerchantRequestMode(
            AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
            AssetPolicy::MODE_THRESHOLD,
        ));
        self::assertFalse($resolver->canMerchantRequestMode(
            AssetPolicy::MODE_MANUAL,
            AssetPolicy::MODE_IMMEDIATE,
        ));
        self::assertFalse($resolver->canMerchantRequestMode(
            AssetPolicy::MODE_DISABLED,
            AssetPolicy::MODE_IMMEDIATE,
        ));
    }
}
