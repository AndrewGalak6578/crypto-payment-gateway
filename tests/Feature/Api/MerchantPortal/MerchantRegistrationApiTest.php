<?php
declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\Role;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_guest_can_register_merchant_and_owner_account(): void
    {
        $response = $this->postJson('/api/auth/merchant/register', [
            'merchant_name' => 'Acme Pay',
            'owner_name' => 'Alice Owner',
            'email' => 'alice.owner@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Alice Owner')
            ->assertJsonPath('data.user.email', 'alice.owner@example.test')
            ->assertJsonPath('data.user.role.slug', 'merchant.owner')
            ->assertJsonPath('data.merchant.name', 'Acme Pay')
            ->assertJsonPath('data.merchant.status', 'active')
            ->assertJsonPath('data.merchant.fee_percent', '2');

        $ownerRoleId = Role::query()->where('slug', 'merchant.owner')->value('id');

        $this->assertDatabaseHas('merchants', [
            'name' => 'Acme Pay',
            'status' => 'active',
            'fee_percent' => '2.000',
        ]);

        $this->assertDatabaseHas('merchant_users', [
            'name' => 'Alice Owner',
            'email' => 'alice.owner@example.test',
            'status' => 'active',
            'role_id' => $ownerRoleId,
        ]);

        $this->assertAuthenticated('merchant');
    }
}
