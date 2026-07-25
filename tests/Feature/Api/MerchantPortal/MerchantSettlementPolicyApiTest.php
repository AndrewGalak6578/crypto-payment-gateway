<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\AssetPolicy;
use App\Models\Capability;
use App\Models\Merchant;
use App\Models\MerchantActivityLog;
use App\Models\MerchantAssetPolicy;
use App\Models\MerchantSettlementPreference;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Models\SuperWallet;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantSettlementPolicyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_get_separates_requested_admin_and_effective_layers_and_reports_unavailable_modes(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'rules-owner@example.test');

        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
            'min_sweep_amount' => '0.01000000',
            'max_gas_cost' => '0.001000000000000000',
        ]);
        MerchantSettlementPreference::query()->create([
            'merchant_id' => $merchant->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'requested_mode' => AssetPolicy::MODE_THRESHOLD,
            'requested_minimum_invoice_payout' => '0.12500000',
            'revision' => 4,
        ]);
        SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bc1qmerchantsettlementdestination',
        ]);

        $response = $this->actingAs($owner, 'merchant')->getJson('/api/merchant/settlement-policies');

        $response->assertOk()
            ->assertJsonPath('data.permissions.can_write', true)
            ->assertJsonPath('data.policies.0.asset.key', 'btc')
            ->assertJsonPath('data.policies.0.revision', 4)
            ->assertJsonPath('data.policies.0.requested.mode', 'threshold')
            ->assertJsonPath('data.policies.0.requested.minimum_invoice_payout', '0.12500000')
            ->assertJsonPath('data.policies.0.inherited.mode', 'immediate')
            ->assertJsonPath('data.policies.0.inherited.minimum_invoice_payout', '0.01000000')
            ->assertJsonPath('data.policies.0.inherited.max_gas_cost.enforcement', false)
            ->assertJsonPath('data.policies.0.effective.mode', 'threshold')
            ->assertJsonPath('data.policies.0.effective.minimum_invoice_payout', '0.12500000')
            ->assertJsonPath('data.policies.0.destination_wallet.ready', true)
            ->assertJsonPath('data.policies.0.destination_wallet.source', 'merchant')
            ->assertJsonPath('data.policies.0.editable.mode.options.2.reason_code', 'operator_release_unavailable')
            ->assertJsonPath('data.policies.0.editable.mode.options.3.reason_code', 'admin_only_custodial_mode');
    }

    public function test_global_wallet_is_not_merchant_readiness_and_fallback_is_disabled_by_default(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'readiness-owner@example.test');
        SuperWallet::query()->create([
            'merchant_id' => null,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bc1qplatformcustodyfallback',
        ]);

        $response = $this->actingAs($owner, 'merchant')->getJson('/api/merchant/settlement-policies');

        $response->assertOk()
            ->assertJsonPath('data.policies.0.destination_wallet.ready', false)
            ->assertJsonPath('data.policies.0.destination_wallet.source', 'none')
            ->assertJsonPath('data.policies.0.destination_wallet.platform_fallback.configured', true)
            ->assertJsonPath('data.policies.0.destination_wallet.platform_fallback.allowed', false);
    }

    public function test_wallet_on_wrong_network_is_not_merchant_readiness(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'network-readiness-owner@example.test');
        SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'litecoin',
            'wallet' => 'bc1qwrongnetworkdestination',
        ]);

        $this->actingAs($owner, 'merchant')
            ->getJson('/api/merchant/settlement-policies')
            ->assertOk()
            ->assertJsonPath('data.policies.0.asset.key', 'btc')
            ->assertJsonPath('data.policies.0.asset.network.key', 'bitcoin')
            ->assertJsonPath('data.policies.0.destination_wallet.ready', false)
            ->assertJsonPath('data.policies.0.destination_wallet.source', 'none');
    }

    public function test_put_revision_zero_creates_preference_without_mutating_admin_policy_and_audits_atomically(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'update-owner@example.test');
        $adminPolicy = MerchantAssetPolicy::query()->create([
            'merchant_id' => $merchant->id,
            'scope_key' => MerchantAssetPolicy::scopeKey('eth_local', 'evm_local'),
            'asset_key' => 'eth_local',
            'network_key' => 'evm_local',
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
        ]);
        $requestId = '019c0d35-4bb8-7ee0-bd49-fb1cf48b4321';

        $response = $this->actingAs($owner, 'merchant')
            ->withHeader('X-Request-ID', $requestId)
            ->putJson('/api/merchant/settlement-policies/eth_local', [
                'revision' => 0,
                'requested' => [
                    'mode' => 'threshold',
                    'minimum_invoice_payout' => '0.123456789012345678',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('data.policy.revision', 1)
            ->assertJsonPath('data.policy.requested.minimum_invoice_payout', '0.123456789012345678');

        $preference = MerchantSettlementPreference::query()->sole();
        self::assertSame('0.123456789012345678', (string) $preference->requested_minimum_invoice_payout);
        self::assertSame(AssetPolicy::MODE_IMMEDIATE, $adminPolicy->fresh()->settlement_mode);

        $activity = MerchantActivityLog::query()->where('action', 'settlement_policy.updated')->sole();
        self::assertSame($owner->id, $activity->actor_merchant_user_id);
        self::assertSame($requestId, $activity->metadata['request_id']);
        self::assertSame('merchant.owner', $activity->metadata['actor']['role_slug']);
        self::assertSame($owner->id, $activity->metadata['actor']['merchant_user_id']);
        self::assertNull($activity->metadata['previous_requested']['mode']);
        self::assertSame('threshold', $activity->metadata['new_requested']['mode']);
        self::assertSame('immediate', $activity->metadata['previous_effective']['mode']);
        self::assertSame('threshold', $activity->metadata['new_effective']['mode']);
        self::assertSame(0, $activity->metadata['revision']['previous']);
        self::assertSame(1, $activity->metadata['revision']['new']);
        self::assertArrayNotHasKey('wallet', $activity->metadata);
        self::assertNotNull($activity->created_at);
    }

    public function test_wildcard_disable_cannot_be_bypassed_by_exact_admin_policy_or_merchant_preference(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'layer-owner@example.test');
        MerchantAssetPolicy::query()->create([
            'merchant_id' => $merchant->id,
            'scope_key' => MerchantAssetPolicy::SCOPE_ALL,
            'forwarding_enabled' => false,
            'reason' => 'Risk review',
        ]);
        MerchantAssetPolicy::query()->create([
            'merchant_id' => $merchant->id,
            'scope_key' => MerchantAssetPolicy::scopeKey('btc', 'bitcoin'),
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_IMMEDIATE,
        ]);
        MerchantSettlementPreference::query()->create([
            'merchant_id' => $merchant->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'requested_mode' => AssetPolicy::MODE_IMMEDIATE,
            'revision' => 1,
        ]);

        $response = $this->actingAs($owner, 'merchant')->getJson('/api/merchant/settlement-policies');

        $response->assertOk()
            ->assertJsonPath('data.policies.0.requested.mode', 'immediate')
            ->assertJsonPath('data.policies.0.inherited.forwarding_enabled', false)
            ->assertJsonPath('data.policies.0.inherited.mode', 'disabled')
            ->assertJsonPath('data.policies.0.effective.mode', 'disabled')
            ->assertJsonPath('data.policies.0.effective.restriction.source', 'merchant_admin_wildcard')
            ->assertJsonPath('data.policies.0.editable.minimum_invoice_payout.reason_code', 'platform_policy_more_restrictive');
    }

    public function test_put_rejects_stale_revision_and_returns_current_resource(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'stale-owner@example.test');
        $payload = [
            'revision' => 0,
            'requested' => ['mode' => 'disabled', 'minimum_invoice_payout' => null],
        ];

        $this->actingAs($owner, 'merchant')
            ->putJson('/api/merchant/settlement-policies/btc', $payload)
            ->assertOk()
            ->assertJsonPath('data.policy.revision', 1);

        $this->putJson('/api/merchant/settlement-policies/btc', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'settlement_policy_revision_conflict')
            ->assertJsonPath('data.policy.revision', 1)
            ->assertJsonPath('data.policy.requested.mode', 'disabled');

        self::assertSame(1, MerchantActivityLog::query()->where('action', 'settlement_policy.updated')->count());
    }

    public function test_put_enforces_canonical_shape_precision_and_platform_minimum(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'validation-owner@example.test');
        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'settlement_mode' => AssetPolicy::MODE_THRESHOLD,
            'min_sweep_amount' => '0.10000000',
        ]);
        $this->actingAs($owner, 'merchant');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => 'threshold', 'minimum_invoice_payout' => '0.01000000'],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.minimum_invoice_payout');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => 'threshold', 'minimum_invoice_payout' => '0.100000001'],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.minimum_invoice_payout');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => 'disabled', 'minimum_invoice_payout' => '1.00000000'],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.minimum_invoice_payout');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => 'manual', 'minimum_invoice_payout' => null],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.mode');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => 'threshold', 'minimum_invoice_payout' => '1234567890123456789.00000000'],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.minimum_invoice_payout');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => null, 'minimum_invoice_payout' => null, 'unknown' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.unknown');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => 'threshold', 'minimum_invoice_payout' => 0.1],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.minimum_invoice_payout');

        $this->putJson('/api/merchant/settlement-policies/btc', [
            'revision' => 0,
            'requested' => ['mode' => null, 'minimum_invoice_payout' => null],
            'unknown' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('unknown');
    }

    public function test_registry_contract_is_fail_closed_and_scoped_to_the_authenticated_merchant(): void
    {
        $merchant = $this->merchant();
        $otherMerchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'registry-owner@example.test');
        MerchantSettlementPreference::query()->create([
            'merchant_id' => $otherMerchant->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'requested_mode' => AssetPolicy::MODE_THRESHOLD,
            'requested_minimum_invoice_payout' => '0.25000000',
            'revision' => 7,
        ]);
        config()->set('assets.disabled_btc', array_merge(config('assets.btc'), [
            'symbol' => 'DBTC',
            'display_name' => 'Disabled Bitcoin',
            'enabled' => false,
        ]));

        $response = $this->actingAs($owner, 'merchant')
            ->getJson('/api/merchant/settlement-policies')
            ->assertOk();
        $policies = collect($response->json('data.policies'));
        $btc = $policies->firstWhere('asset.key', 'btc');
        $disabled = $policies->firstWhere('asset.key', 'disabled_btc');

        self::assertNull($btc['requested']['mode']);
        self::assertSame(0, $btc['revision']);
        self::assertFalse($disabled['inherited']['asset_enabled']);
        self::assertSame(AssetPolicy::MODE_DISABLED, $disabled['effective']['mode']);

        $this->putJson('/api/merchant/settlement-policies/not_registered', [
            'revision' => 0,
            'requested' => ['mode' => null, 'minimum_invoice_payout' => null],
        ])->assertNotFound();

        $this->putJson('/api/merchant/settlement-policies/disabled_btc', [
            'revision' => 0,
            'requested' => ['mode' => 'immediate', 'minimum_invoice_payout' => null],
        ])->assertUnprocessable()->assertJsonValidationErrors('requested.mode');

        $this->putJson('/api/merchant/settlement-policies/disabled_btc', [
            'revision' => 0,
            'requested' => ['mode' => 'disabled', 'minimum_invoice_payout' => null],
        ])->assertOk()
            ->assertJsonPath('data.policy.effective.mode', AssetPolicy::MODE_DISABLED);

        $otherPreference = MerchantSettlementPreference::query()
            ->where('merchant_id', $otherMerchant->id)
            ->sole();
        self::assertSame(7, $otherPreference->revision);
        self::assertSame('0.250000000000000000', (string) $otherPreference->requested_minimum_invoice_payout);
    }

    public function test_revision_must_be_a_bounded_json_integer(): void
    {
        $merchant = $this->merchant();
        $owner = $this->merchantUser($merchant, 'merchant.owner', 'revision-owner@example.test');
        $payload = [
            'requested' => ['mode' => null, 'minimum_invoice_payout' => null],
        ];
        $this->actingAs($owner, 'merchant');

        $this->putJson('/api/merchant/settlement-policies/btc', array_merge($payload, [
            'revision' => '0',
        ]))->assertUnprocessable()->assertJsonValidationErrors('revision');

        $this->putJson('/api/merchant/settlement-policies/btc', array_merge($payload, [
            'revision' => MerchantSettlementPreference::MAX_EXPECTED_REVISION + 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('revision');

        $preference = MerchantSettlementPreference::query()->create([
            'merchant_id' => $merchant->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'requested_mode' => null,
            'requested_minimum_invoice_payout' => null,
            'revision' => MerchantSettlementPreference::MAX_EXPECTED_REVISION,
        ]);

        $this->putJson('/api/merchant/settlement-policies/btc', array_merge($payload, [
            'revision' => MerchantSettlementPreference::MAX_EXPECTED_REVISION,
        ]))->assertUnprocessable()->assertJsonValidationErrors('revision');

        self::assertSame(
            MerchantSettlementPreference::MAX_EXPECTED_REVISION,
            $preference->fresh()->revision,
        );
        self::assertSame(1, MerchantSettlementPreference::query()->where('merchant_id', $merchant->id)->count());
    }

    public function test_capability_data_migration_is_idempotent_and_rollback_is_non_destructive(): void
    {
        $owner = Role::query()->where('slug', 'merchant.owner')->sole();
        $analyst = Role::query()->where('slug', 'merchant.analyst')->sole();
        $read = Capability::query()->where('code', 'settlements.read')->sole();
        $createdAt = now('UTC')->subYears(2)->startOfSecond();
        $read->forceFill([
            'name' => 'Pre-existing settlement reader',
            'description' => 'Production-owned capability metadata',
            'created_at' => $createdAt,
        ])->save();
        Capability::query()->where('code', 'settlements.write')->delete();
        $owner->capabilities()->detach($read->id);
        $analyst->capabilities()->detach($read->id);
        $custom = Capability::query()->create([
            'code' => 'merchant.custom.retained',
            'name' => 'Retained custom capability',
        ]);
        $owner->capabilities()->attach($custom->id);

        $migration = require database_path('migrations/2026_07_23_000100_add_merchant_settlement_capabilities.php');
        $migration->up();
        $migration->up();

        $owner->refresh()->load('capabilities');
        $analyst->refresh()->load('capabilities');
        $preservedRead = Capability::query()->where('code', 'settlements.read')->sole();
        self::assertSame('Pre-existing settlement reader', $preservedRead->name);
        self::assertSame('Production-owned capability metadata', $preservedRead->description);
        self::assertSame($createdAt->toDateTimeString(), $preservedRead->created_at->toDateTimeString());
        self::assertTrue($owner->capabilities->contains('code', 'merchant.custom.retained'));
        self::assertTrue($owner->capabilities->contains('code', 'settlements.read'));
        self::assertTrue($owner->capabilities->contains('code', 'settlements.write'));
        self::assertTrue($analyst->capabilities->contains('code', 'settlements.read'));
        self::assertFalse($analyst->capabilities->contains('code', 'settlements.write'));
        self::assertSame(1, Capability::query()->where('code', 'settlements.read')->count());
        self::assertSame(1, Capability::query()->where('code', 'settlements.write')->count());

        $migration->down();
        $owner->refresh()->load('capabilities');
        $analyst->refresh()->load('capabilities');
        self::assertTrue(Capability::query()->where('code', 'settlements.read')->exists());
        self::assertTrue(Capability::query()->where('code', 'settlements.write')->exists());
        self::assertTrue(Capability::query()->where('code', 'merchant.custom.retained')->exists());
        self::assertTrue($owner->capabilities->contains('code', 'merchant.custom.retained'));
        self::assertTrue($owner->capabilities->contains('code', 'settlements.read'));
        self::assertTrue($owner->capabilities->contains('code', 'settlements.write'));
        self::assertTrue($analyst->capabilities->contains('code', 'settlements.read'));

        $migration->up();
        self::assertSame(1, Capability::query()->where('code', 'settlements.read')->count());
        self::assertSame(1, Capability::query()->where('code', 'settlements.write')->count());
    }

    public function test_settlement_capabilities_allow_read_only_roles_to_read_but_not_write(): void
    {
        $merchant = $this->merchant();

        foreach (['merchant.analyst', 'merchant.viewer'] as $index => $role) {
            $user = $this->merchantUser($merchant, $role, "read-only-{$index}@example.test");
            $this->actingAs($user, 'merchant')
                ->getJson('/api/merchant/settlement-policies')
                ->assertOk()
                ->assertJsonPath('data.permissions.can_write', false);
            $this->putJson('/api/merchant/settlement-policies/btc', [
                'revision' => 0,
                'requested' => ['mode' => null, 'minimum_invoice_payout' => null],
            ])->assertForbidden();
        }

        foreach (['merchant.owner', 'merchant.admin'] as $index => $role) {
            $user = $this->merchantUser($merchant, $role, "writer-{$index}@example.test");
            $this->actingAs($user, 'merchant')
                ->putJson('/api/merchant/settlement-policies/'.($index === 0 ? 'btc' : 'ltc'), [
                    'revision' => 0,
                    'requested' => ['mode' => null, 'minimum_invoice_payout' => null],
                ])->assertOk();
        }
    }

    private function merchant(): Merchant
    {
        return Merchant::query()->create([
            'name' => 'Settlement Rules Merchant',
            'status' => 'active',
            'fee_percent' => '2.00',
        ]);
    }

    private function merchantUser(Merchant $merchant, string $roleSlug, string $email): MerchantUser
    {
        return MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => $roleSlug,
            'email' => $email,
            'password' => 'password123',
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'status' => 'active',
        ]);
    }
}
