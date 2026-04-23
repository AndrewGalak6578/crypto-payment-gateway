<?php
declare(strict_types=1);

namespace Tests\Feature\Api\AdminPortal;

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\SuperWallet;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminMerchantWalletApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_list_merchant_wallets(): void
    {
        $merchant = Merchant::query()->create(['name' => 'Merchant A']);
        $otherMerchant = Merchant::query()->create(['name' => 'Merchant B']);

        SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bc1merchant',
            'fee_rate' => '0.10',
        ]);

        SuperWallet::query()->create([
            'merchant_id' => $otherMerchant->id,
            'coin' => 'ltc',
            'asset_key' => 'ltc',
            'network_key' => 'litecoin',
            'wallet' => 'ltc1other',
            'fee_rate' => null,
        ]);

        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->getJson("/api/admin/merchants/{$merchant->id}/wallets");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.coin', 'BTC')
            ->assertJsonPath('data.0.asset_key', 'btc')
            ->assertJsonPath('data.0.network_key', 'bitcoin')
            ->assertJsonPath('data.0.wallet', 'bc1merchant');
    }

    public function test_admin_can_create_merchant_wallet(): void
    {
        $merchant = Merchant::query()->create(['name' => 'Merchant A']);
        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->postJson("/api/admin/merchants/{$merchant->id}/wallets", [
            'coin' => 'btc',
            'wallet' => 'bc1newwallet',
            'fee_rate' => 0.25,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.coin', 'BTC')
            ->assertJsonPath('data.asset_key', 'btc')
            ->assertJsonPath('data.network_key', 'bitcoin')
            ->assertJsonPath('data.wallet', 'bc1newwallet')
            ->assertJsonPath('data.fee_rate', '0.25');

        $this->assertDatabaseHas('super_wallets', [
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bc1newwallet',
        ]);
    }

    public function test_admin_can_update_merchant_wallet(): void
    {
        $merchant = Merchant::query()->create(['name' => 'Merchant A']);
        $wallet = SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bc1old',
            'fee_rate' => '0.10',
        ]);

        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->putJson("/api/admin/merchants/{$merchant->id}/wallets/{$wallet->id}", [
            'wallet' => 'bc1updated',
            'fee_rate' => 0.4,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $wallet->id)
            ->assertJsonPath('data.wallet', 'bc1updated')
            ->assertJsonPath('data.fee_rate', '0.4');

        $this->assertDatabaseHas('super_wallets', [
            'id' => $wallet->id,
            'merchant_id' => $merchant->id,
            'wallet' => 'bc1updated',
        ]);
    }

    public function test_admin_can_delete_merchant_wallet(): void
    {
        $merchant = Merchant::query()->create(['name' => 'Merchant A']);
        $wallet = SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bc1to-delete',
            'fee_rate' => null,
        ]);

        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->deleteJson("/api/admin/merchants/{$merchant->id}/wallets/{$wallet->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('super_wallets', [
            'id' => $wallet->id,
        ]);
    }

    public function test_admin_cannot_mutate_wallet_from_another_merchant(): void
    {
        $merchantA = Merchant::query()->create(['name' => 'Merchant A']);
        $merchantB = Merchant::query()->create(['name' => 'Merchant B']);

        $walletB = SuperWallet::query()->create([
            'merchant_id' => $merchantB->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bc1merchant-b',
            'fee_rate' => '0.05',
        ]);

        $this->actingAs($this->makeAdmin(), 'admin');

        $update = $this->putJson("/api/admin/merchants/{$merchantA->id}/wallets/{$walletB->id}", [
            'wallet' => 'bc1hijacked',
            'fee_rate' => 1,
        ]);
        $update->assertNotFound();

        $delete = $this->deleteJson("/api/admin/merchants/{$merchantA->id}/wallets/{$walletB->id}");
        $delete->assertNotFound();

        $this->assertDatabaseHas('super_wallets', [
            'id' => $walletB->id,
            'merchant_id' => $merchantB->id,
            'wallet' => 'bc1merchant-b',
        ]);
    }

    private function makeAdmin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password123',
            'role' => AdminUser::ROLE_SUPER_ADMIN,
            'status' => AdminUser::STATUS_ACTIVE,
        ]);
    }
}
