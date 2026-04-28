<?php
declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantUserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_owner_can_manage_users_within_own_merchant(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Merchant A',
            'status' => 'active',
            'fee_percent' => 2.00,
        ]);

        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner@example.test');
        $adminRoleId = $this->roleId('merchant.admin');

        $this->actingAs($owner, 'merchant');

        $createResponse = $this->postJson('/api/merchant/merchant-users', [
            'name' => 'Ops Admin',
            'email' => 'ops@example.test',
            'password' => 'password123',
            'role_id' => $adminRoleId,
            'status' => 'active',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'ops@example.test')
            ->assertJsonPath('data.role_slug', 'merchant.admin');

        $createdUserId = (int) $createResponse->json('data.id');

        $listResponse = $this->getJson('/api/merchant/merchant-users');

        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.data');

        $disableResponse = $this->patchJson("/api/merchant/merchant-users/{$createdUserId}/status", [
            'status' => 'disabled',
        ]);

        $disableResponse->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $viewerRoleId = $this->roleId('merchant.viewer');
        $roleResponse = $this->patchJson("/api/merchant/merchant-users/{$createdUserId}/role", [
            'role_id' => $viewerRoleId,
        ]);

        $roleResponse->assertOk()
            ->assertJsonPath('data.role_slug', 'merchant.viewer');

        $this->assertDatabaseHas('merchant_users', [
            'id' => $createdUserId,
            'merchant_id' => $merchant->id,
            'status' => 'disabled',
            'role_id' => $viewerRoleId,
        ]);
    }

    public function test_admin_cannot_create_or_update_merchant_users_without_write_capability(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Merchant A',
            'status' => 'active',
            'fee_percent' => 2.00,
        ]);

        $adminUser = $this->createMerchantUser($merchant, 'merchant.admin', 'admin@example.test');
        $viewer = $this->createMerchantUser($merchant, 'merchant.viewer', 'viewer@example.test');

        $this->actingAs($adminUser, 'merchant');

        $createResponse = $this->postJson('/api/merchant/merchant-users', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.test',
            'password' => 'password123',
            'role_id' => $this->roleId('merchant.viewer'),
            'status' => 'active',
        ]);

        $createResponse->assertForbidden();

        $statusResponse = $this->patchJson("/api/merchant/merchant-users/{$viewer->id}/status", [
            'status' => 'disabled',
        ]);

        $statusResponse->assertForbidden();
    }

    public function test_owner_cannot_disable_last_active_owner(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Merchant A',
            'status' => 'active',
            'fee_percent' => 2.00,
        ]);

        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner@example.test');

        $this->actingAs($owner, 'merchant');

        $response = $this->patchJson("/api/merchant/merchant-users/{$owner->id}/status", [
            'status' => 'disabled',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'At least one active owner must remain.');

        $this->assertDatabaseHas('merchant_users', [
            'id' => $owner->id,
            'status' => 'active',
        ]);
    }

    private function createMerchantUser(Merchant $merchant, string $roleSlug, string $email): MerchantUser
    {
        return MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => 'password123',
            'role_id' => $this->roleId($roleSlug),
            'status' => 'active',
        ]);
    }

    private function roleId(string $slug): int
    {
        return (int) Role::query()->where('slug', $slug)->value('id');
    }
}
