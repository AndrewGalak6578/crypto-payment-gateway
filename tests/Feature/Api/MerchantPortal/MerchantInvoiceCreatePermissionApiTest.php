<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantInvoiceCreatePermissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_owner_can_create_universal_invoice_with_write_capability(): void
    {
        $merchant = $this->createMerchant();
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-create@example.test');

        $this->actingAs($owner, 'merchant');

        $response = $this->postJson('/api/merchant/invoices', [
            'amount_usd' => 10.00,
            'coin' => null,
            'external_id' => 'order-create-1',
            'metadata' => [
                'redirects' => [
                    'success_url' => 'https://merchant.example/success',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_asset')
            ->assertJsonPath('data.external_id', 'order-create-1');

        $this->assertDatabaseHas('invoices', [
            'merchant_id' => $merchant->id,
            'external_id' => 'order-create-1',
            'status' => 'awaiting_asset',
            'coin' => null,
        ]);
    }

    public function test_admin_can_create_invoice_with_write_capability(): void
    {
        $merchant = $this->createMerchant();
        $admin = $this->createMerchantUser($merchant, 'merchant.admin', 'admin-create@example.test');

        $this->actingAs($admin, 'merchant');

        $response = $this->postJson('/api/merchant/invoices', [
            'amount_usd' => 25.50,
            'coin' => null,
            'external_id' => 'order-create-admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_asset');

        $this->assertDatabaseHas('invoices', [
            'merchant_id' => $merchant->id,
            'external_id' => 'order-create-admin',
        ]);
    }

    public function test_viewer_cannot_create_invoice_without_write_capability(): void
    {
        $merchant = $this->createMerchant();
        $viewer = $this->createMerchantUser($merchant, 'merchant.viewer', 'viewer-create@example.test');

        $this->actingAs($viewer, 'merchant');

        $response = $this->postJson('/api/merchant/invoices', [
            'amount_usd' => 10.00,
            'coin' => null,
            'external_id' => 'blocked-order',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('invoices', [
            'merchant_id' => $merchant->id,
            'external_id' => 'blocked-order',
        ]);
    }

    private function createMerchant(): Merchant
    {
        return Merchant::query()->create([
            'name' => 'Merchant A',
            'status' => 'active',
            'fee_percent' => 2.00,
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
