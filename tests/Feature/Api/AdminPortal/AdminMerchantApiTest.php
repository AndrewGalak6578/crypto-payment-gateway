<?php
declare(strict_types=1);

namespace Tests\Feature\Api\AdminPortal;

use App\Models\AdminUser;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminMerchantApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_merchant_with_minimal_payload(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->postJson('/api/admin/merchants', [
            'name' => 'Acme Store',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Acme Store')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.has_webhook_secret', false);

        $this->assertDatabaseHas('merchants', [
            'name' => 'Acme Store',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_create_merchant_with_optional_fields(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->postJson('/api/admin/merchants', [
            'name' => 'Merchant Pro',
            'status' => 'disabled',
            'fee_percent' => 1.5,
            'webhook_url' => 'https://example.test/webhook',
            'webhook_secret' => 'top-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Merchant Pro')
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.fee_percent', '1.5')
            ->assertJsonPath('data.webhook_url', 'https://example.test/webhook')
            ->assertJsonPath('data.has_webhook_secret', true);

        $this->assertDatabaseHas('merchants', [
            'name' => 'Merchant Pro',
            'status' => 'disabled',
            'webhook_url' => 'https://example.test/webhook',
            'webhook_secret' => 'top-secret',
        ]);
    }

    public function test_admin_create_merchant_requires_name(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin');

        $response = $this->postJson('/api/admin/merchants', [
            'name' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    private function makeAdmin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin-merchant-create@example.test',
            'password' => 'password123',
            'role' => AdminUser::ROLE_SUPER_ADMIN,
            'status' => AdminUser::STATUS_ACTIVE,
        ]);
    }
}
