<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Models\SuperWallet;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantWalletApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_owner_can_manage_own_destination_wallets(): void
    {
        $merchant = $this->createMerchant();
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'wallet-owner@example.test');
        $this->actingAs($owner, 'merchant');

        $createResponse = $this->postJson('/api/merchant/wallets', [
            'coin' => 'btc',
            'wallet' => 'bcrt1qmerchantdestination000000000000000000',
            'fee_rate' => '1.25',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.asset_key', 'btc')
            ->assertJsonPath('data.network_key', 'bitcoin');

        $walletId = $createResponse->json('data.id');

        $this->putJson("/api/merchant/wallets/{$walletId}", [
            'wallet' => 'bcrt1qmerchantdestination111111111111111111',
            'fee_rate' => '2.5',
        ])->assertOk()
            ->assertJsonPath('data.wallet', 'bcrt1qmerchantdestination111111111111111111')
            ->assertJsonPath('data.fee_rate', '2.5');

        $this->getJson('/api/merchant/wallets')
            ->assertOk()
            ->assertJsonPath('data.0.wallet', 'bcrt1qmerchantdestination111111111111111111');

        $this->deleteJson("/api/merchant/wallets/{$walletId}")
            ->assertOk();

        $this->assertDatabaseMissing('super_wallets', ['id' => $walletId]);
    }

    public function test_viewer_cannot_write_wallets(): void
    {
        $merchant = $this->createMerchant();
        $viewer = $this->createMerchantUser($merchant, 'merchant.viewer', 'wallet-viewer@example.test');
        $this->actingAs($viewer, 'merchant');

        $this->postJson('/api/merchant/wallets', [
            'coin' => 'btc',
            'wallet' => 'bcrt1qmerchantdestination',
        ])->assertForbidden();
    }

    public function test_wallet_validation_rejects_invalid_evm_address_and_whitespace(): void
    {
        $merchant = $this->createMerchant();
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'wallet-validation@example.test');
        $this->actingAs($owner, 'merchant');

        $this->postJson('/api/merchant/wallets', [
            'coin' => 'eth_local',
            'wallet' => 'not-an-evm-address',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('wallet');

        $this->postJson('/api/merchant/wallets', [
            'coin' => 'btc',
            'wallet' => 'bcrt1q has-space',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('wallet');
    }

    public function test_owner_cannot_update_another_merchants_wallet(): void
    {
        $merchant = $this->createMerchant();
        $otherMerchant = $this->createMerchant();
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'wallet-scope@example.test');
        $otherWallet = SuperWallet::query()->create([
            'merchant_id' => $otherMerchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qothermerchantdestination',
        ]);

        $this->actingAs($owner, 'merchant');

        $this->putJson("/api/merchant/wallets/{$otherWallet->id}", [
            'wallet' => 'bcrt1qshouldnotupdate',
        ])->assertNotFound();
    }

    private function createMerchant(): Merchant
    {
        return Merchant::query()->create([
            'name' => 'Wallet Merchant',
            'status' => 'active',
            'fee_percent' => 1.5,
        ]);
    }

    private function createMerchantUser(Merchant $merchant, string $roleSlug, string $email): MerchantUser
    {
        return MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => 'password123',
            'role_id' => (int) Role::query()->where('slug', $roleSlug)->value('id'),
            'status' => 'active',
        ]);
    }
}
