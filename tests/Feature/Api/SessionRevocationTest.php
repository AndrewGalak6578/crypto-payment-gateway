<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_disabled_admin_gets_one_forbidden_response_then_session_is_unauthenticated(): void
    {
        $admin = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
        $this->actingAs($admin, 'admin');

        $admin->update(['status' => AdminUser::STATUS_DISABLED]);

        $this->getJson('/api/admin/dashboard')->assertForbidden();
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_admin_role_change_is_effective_on_the_next_open_session_request(): void
    {
        $admin = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
        $this->actingAs($admin, 'admin');

        $admin->update(['role' => AdminUser::ROLE_ANALYST]);

        $this->postJson('/api/admin/merchants', ['name' => 'Must Not Exist'])
            ->assertForbidden();
        $this->assertDatabaseMissing('merchants', ['name' => 'Must Not Exist']);
    }

    public function test_disabled_merchant_user_is_revoked_before_me_or_controller_execution(): void
    {
        [$merchant, $user] = $this->merchantSession();
        $this->actingAs($user, 'merchant');

        $user->update(['status' => 'disabled']);

        $this->getJson('/api/auth/merchant/me')->assertForbidden();
        $this->getJson('/api/auth/merchant/me')->assertUnauthorized();
        self::assertSame('Original Merchant', $merchant->fresh()->name);
    }

    public function test_disabled_merchant_tenant_is_revoked_before_me_or_mutation(): void
    {
        [$merchant, $user] = $this->merchantSession();
        $this->actingAs($user, 'merchant');

        $merchant->update(['status' => 'disabled']);

        $this->putJson('/api/merchant/settings', [
            'name' => 'Mutated While Disabled',
        ])->assertForbidden();

        self::assertSame('Original Merchant', $merchant->fresh()->name);
        $this->getJson('/api/auth/merchant/me')->assertUnauthorized();
    }

    public function test_logout_remains_safe_when_merchant_user_or_tenant_was_disabled(): void
    {
        [$merchant, $user] = $this->merchantSession();
        $this->actingAs($user, 'merchant');

        $user->update(['status' => 'disabled']);
        $merchant->update(['status' => 'disabled']);

        $this->postJson('/api/auth/merchant/logout')->assertOk();
        $this->getJson('/api/auth/merchant/me')->assertUnauthorized();
    }

    private function admin(string $role): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Session Admin',
            'email' => uniqid('session-admin-', true).'@example.test',
            'password' => 'password123',
            'role' => $role,
            'status' => AdminUser::STATUS_ACTIVE,
        ]);
    }

    /** @return array{Merchant, MerchantUser} */
    private function merchantSession(): array
    {
        $merchant = Merchant::query()->create([
            'name' => 'Original Merchant',
            'status' => 'active',
            'fee_percent' => '1.00',
        ]);
        $user = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Session Merchant User',
            'email' => uniqid('session-merchant-', true).'@example.test',
            'password' => 'password123',
            'role_id' => Role::query()->where('slug', 'merchant.owner')->value('id'),
            'status' => 'active',
        ]);

        return [$merchant, $user];
    }
}
