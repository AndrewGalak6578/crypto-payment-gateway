<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\AssetPolicy;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_owner_can_read_and_update_checkout_settings(): void
    {
        $merchant = $this->createMerchant();
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-settings@example.test');

        $this->actingAs($owner, 'merchant');

        $this->getJson('/api/merchant/settings')
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Merchant A')
            ->assertJsonPath('data.billing.fee_percent', 2);

        $response = $this->putJson('/api/merchant/settings', [
            'checkout_display_name' => 'Acme checkout',
            'checkout_support_email' => 'support@example.test',
            'checkout_brand_color' => '#16a34a',
            'checkout_expires_minutes' => 45,
            'checkout_payer_can_choose_asset' => true,
            'checkout_default_asset' => null,
            'checkout_allowed_assets' => ['btc', 'dash'],
            'checkout_success_url' => 'https://merchant.example/success',
            'checkout_cancel_url' => 'https://merchant.example/cancel',
            'checkout_auto_redirect' => true,
            'checkout_redirect_delay_seconds' => 7,
            'checkout_show_invoice_id' => true,
            'checkout_show_support_email' => true,
            'checkout_partial_payment_policy' => 'allow_top_up',
            'checkout_confirmation_display' => 'simple',
            'checkout_min_amount_usd' => 5,
            'checkout_max_amount_usd' => 500,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.checkout.display_name', 'Acme checkout')
            ->assertJsonPath('data.checkout.expires_minutes', 45)
            ->assertJsonPath('data.checkout.allowed_assets.0', 'btc')
            ->assertJsonPath('data.checkout.redirect_delay_seconds', 7)
            ->assertJsonPath('data.checkout.success_url', 'https://merchant.example/success');

        $this->assertDatabaseHas('merchants', [
            'id' => $merchant->id,
            'checkout_display_name' => 'Acme checkout',
            'checkout_expires_minutes' => 45,
        ]);
    }

    public function test_viewer_cannot_update_checkout_settings(): void
    {
        $merchant = $this->createMerchant();
        $viewer = $this->createMerchantUser($merchant, 'merchant.viewer', 'viewer-settings@example.test');

        $this->actingAs($viewer, 'merchant');

        $this->putJson('/api/merchant/settings', [
            'checkout_payer_can_choose_asset' => true,
        ])->assertForbidden();
    }

    public function test_invoice_creation_applies_checkout_expiration_and_redirect_defaults(): void
    {
        $merchant = $this->createMerchant([
            'checkout_expires_minutes' => 30,
            'checkout_payer_can_choose_asset' => true,
            'checkout_success_url' => 'https://merchant.example/success',
            'checkout_cancel_url' => 'https://merchant.example/cancel',
        ]);
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-invoice-defaults@example.test');

        $this->actingAs($owner, 'merchant');

        $response = $this->postJson('/api/merchant/invoices', [
            'amount_usd' => 10.00,
            'external_id' => 'settings-defaults-order',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_asset');

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('external_id', 'settings-defaults-order')->firstOrFail();

        $this->assertSame('https://merchant.example/success', $invoice->metadata['redirects']['success_url'] ?? null);
        $this->assertSame('https://merchant.example/cancel', $invoice->metadata['redirects']['cancel_url'] ?? null);
        $this->assertTrue($invoice->expires_at->between(now('UTC')->addMinutes(29), now('UTC')->addMinutes(31)));
    }

    public function test_invoice_creation_respects_amount_guardrails(): void
    {
        $merchant = $this->createMerchant([
            'checkout_payer_can_choose_asset' => true,
            'checkout_min_amount_usd' => 20,
            'checkout_max_amount_usd' => 40,
        ]);
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-guardrails@example.test');

        $this->actingAs($owner, 'merchant');

        $this->postJson('/api/merchant/invoices', [
            'amount_usd' => 10.00,
            'external_id' => 'below-minimum',
        ])->assertStatus(422)
            ->assertJsonPath('errors.amount_usd.0', 'Amount is below the merchant minimum.');
    }

    public function test_checkout_settings_reject_policy_blocked_assets(): void
    {
        AssetPolicy::query()->create([
            'asset_key' => 'dash',
            'network_key' => 'dash',
            'asset_enabled' => true,
            'checkout_enabled' => false,
            'forwarding_enabled' => true,
        ]);

        $merchant = $this->createMerchant();
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-settings-policy@example.test');

        $this->actingAs($owner, 'merchant');

        $response = $this->putJson('/api/merchant/settings', [
            'checkout_display_name' => 'Acme checkout',
            'checkout_support_email' => null,
            'checkout_brand_color' => '#16a34a',
            'checkout_expires_minutes' => 45,
            'checkout_payer_can_choose_asset' => true,
            'checkout_default_asset' => null,
            'checkout_allowed_assets' => ['btc', 'dash'],
            'checkout_success_url' => null,
            'checkout_cancel_url' => null,
            'checkout_auto_redirect' => true,
            'checkout_redirect_delay_seconds' => 7,
            'checkout_show_invoice_id' => true,
            'checkout_show_support_email' => true,
            'checkout_partial_payment_policy' => 'allow_top_up',
            'checkout_confirmation_display' => 'simple',
            'checkout_min_amount_usd' => 5,
            'checkout_max_amount_usd' => 500,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('checkout_allowed_assets.1');
    }

    private function createMerchant(array $overrides = []): Merchant
    {
        return Merchant::query()->create(array_merge([
            'name' => 'Merchant A',
            'status' => 'active',
            'fee_percent' => 2.00,
        ], $overrides));
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
