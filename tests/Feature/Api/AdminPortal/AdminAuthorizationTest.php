<?php

declare(strict_types=1);

namespace Tests\Feature\Api\AdminPortal;

use App\Jobs\DeliverWebhookJob;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Models\SuperWallet;
use App\Models\WebhookDelivery;
use App\Services\AdminPortalAccess;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
        Queue::fake();
    }

    #[DataProvider('functionalRouteProvider')]
    public function test_every_functional_route_requires_authentication(string $case): void
    {
        [$method, $url, $payload] = $this->requestFor($case);

        $this->json($method, $url, $payload)->assertUnauthorized();
    }

    #[DataProvider('functionalRouteProvider')]
    public function test_super_admin_has_each_explicit_functional_capability(string $case): void
    {
        $this->actingAs($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'admin');
        [$method, $url, $payload] = $this->requestFor($case);

        $response = $this->json($method, $url, $payload);

        self::assertNotContains($response->getStatusCode(), [401, 403], $response->getContent());
    }

    #[DataProvider('supportRouteProvider')]
    public function test_support_has_only_the_agreed_operations(string $case, bool $allowed): void
    {
        $this->actingAs($this->admin(AdminUser::ROLE_SUPPORT), 'admin');
        [$method, $url, $payload] = $this->requestFor($case);

        $response = $this->json($method, $url, $payload);

        if ($allowed) {
            self::assertNotContains($response->getStatusCode(), [401, 403], $response->getContent());
        } else {
            $response->assertForbidden();
        }
    }

    #[DataProvider('analystRouteProvider')]
    public function test_analyst_is_read_only_on_every_route(string $case, bool $allowed): void
    {
        $this->actingAs($this->admin(AdminUser::ROLE_ANALYST), 'admin');
        [$method, $url, $payload] = $this->requestFor($case);

        $response = $this->json($method, $url, $payload);

        if ($allowed) {
            self::assertNotContains($response->getStatusCode(), [401, 403], $response->getContent());
        } else {
            $response->assertForbidden();
        }
    }

    #[DataProvider('functionalRouteProvider')]
    public function test_unknown_role_is_denied_on_every_functional_route(string $case): void
    {
        $this->actingAs($this->admin('unknown_role'), 'admin');
        [$method, $url, $payload] = $this->requestFor($case);

        $this->json($method, $url, $payload)->assertForbidden();
    }

    public function test_unknown_capability_is_denied_even_to_super_admin(): void
    {
        self::assertFalse(
            app(AdminPortalAccess::class)->can(
                $this->admin(AdminUser::ROLE_SUPER_ADMIN),
                'unknown.capability',
            ),
        );
    }

    public function test_support_can_disable_but_cannot_enable_merchant_or_user_even_idempotently(): void
    {
        $this->actingAs($this->admin(AdminUser::ROLE_SUPPORT), 'admin');
        $merchant = $this->merchant();
        $merchantUser = $this->merchantUser($merchant);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'disabled'])
            ->assertOk();
        $this->patchJson("/api/admin/merchant-users/{$merchantUser->id}/status", ['status' => 'disabled'])
            ->assertOk();

        self::assertSame('disabled', $merchant->fresh()->status);
        self::assertSame('disabled', $merchantUser->fresh()->status);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'active'])
            ->assertForbidden();
        $this->patchJson("/api/admin/merchant-users/{$merchantUser->id}/status", ['status' => 'active'])
            ->assertForbidden();

        self::assertSame('disabled', $merchant->fresh()->status);
        self::assertSame('disabled', $merchantUser->fresh()->status);

        $merchant->update(['status' => 'active']);
        $merchantUser->update(['status' => 'active']);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'active'])
            ->assertForbidden();
        $this->patchJson("/api/admin/merchant-users/{$merchantUser->id}/status", ['status' => 'active'])
            ->assertForbidden();
    }

    public function test_denied_mutations_do_not_change_database_or_dispatch_jobs(): void
    {
        $this->actingAs($this->admin(AdminUser::ROLE_ANALYST), 'admin');
        $merchant = $this->merchant();
        $user = $this->merchantUser($merchant);
        $key = $this->apiKey($merchant);
        $delivery = $this->delivery($merchant);

        $this->postJson('/api/admin/merchants', ['name' => 'Denied'])->assertForbidden();
        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'disabled'])->assertForbidden();
        $this->patchJson("/api/admin/merchant-users/{$user->id}/status", ['status' => 'disabled'])->assertForbidden();
        $this->postJson("/api/admin/webhook-deliveries/{$delivery->id}/retry")->assertForbidden();
        $this->postJson("/api/admin/merchant-api-keys/{$key->id}/revoke")->assertForbidden();

        $this->assertDatabaseMissing('merchants', ['name' => 'Denied']);
        self::assertSame('active', $merchant->fresh()->status);
        self::assertSame('active', $user->fresh()->status);
        self::assertSame('failed', $delivery->fresh()->status);
        self::assertNull($key->fresh()->revoked_at);
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    /** @return array<string, array{string}> */
    public static function functionalRouteProvider(): array
    {
        return collect(self::cases())->mapWithKeys(
            static fn (array $case, string $name): array => [$name => [$name]],
        )->all();
    }

    /** @return array<string, array{string, bool}> */
    public static function supportRouteProvider(): array
    {
        return collect(self::cases())->mapWithKeys(
            static fn (array $case, string $name): array => [$name => [$name, $case['support']]],
        )->all();
    }

    /** @return array<string, array{string, bool}> */
    public static function analystRouteProvider(): array
    {
        return collect(self::cases())->mapWithKeys(
            static fn (array $case, string $name): array => [$name => [$name, $case['analyst']]],
        )->all();
    }

    /** @return array<string, array{support: bool, analyst: bool}> */
    private static function cases(): array
    {
        return [
            'dashboard.read' => ['support' => true, 'analyst' => true],
            'merchants.index' => ['support' => true, 'analyst' => true],
            'merchants.create' => ['support' => false, 'analyst' => false],
            'merchants.show' => ['support' => true, 'analyst' => true],
            'merchants.status.disable' => ['support' => true, 'analyst' => false],
            'wallets.index' => ['support' => true, 'analyst' => true],
            'wallets.create' => ['support' => false, 'analyst' => false],
            'wallets.update' => ['support' => false, 'analyst' => false],
            'wallets.delete' => ['support' => false, 'analyst' => false],
            'merchant_users.index' => ['support' => true, 'analyst' => true],
            'merchant_users.create' => ['support' => false, 'analyst' => false],
            'merchant_users.role' => ['support' => false, 'analyst' => false],
            'merchant_users.status.disable' => ['support' => true, 'analyst' => false],
            'invoices.index' => ['support' => true, 'analyst' => true],
            'invoices.show' => ['support' => true, 'analyst' => true],
            'invoices.refresh' => ['support' => true, 'analyst' => false],
            'webhooks.index' => ['support' => true, 'analyst' => true],
            'webhooks.show' => ['support' => true, 'analyst' => true],
            'webhooks.retry' => ['support' => true, 'analyst' => false],
            'api_keys.index' => ['support' => true, 'analyst' => true],
            'api_keys.revoke' => ['support' => true, 'analyst' => false],
        ];
    }

    /** @return array{string, string, array<string, mixed>} */
    private function requestFor(string $case): array
    {
        $merchant = $this->merchant();
        $user = $this->merchantUser($merchant);
        $wallet = $this->wallet($merchant);
        $invoice = $this->invoice($merchant);
        $delivery = $this->delivery($merchant, $invoice);
        $apiKey = $this->apiKey($merchant);
        $role = Role::query()->where('slug', 'merchant.analyst')->firstOrFail();

        return match ($case) {
            'dashboard.read' => ['GET', '/api/admin/dashboard', []],
            'merchants.index' => ['GET', '/api/admin/merchants', []],
            'merchants.create' => ['POST', '/api/admin/merchants', ['name' => 'Created Merchant']],
            'merchants.show' => ['GET', "/api/admin/merchants/{$merchant->id}", []],
            'merchants.status.disable' => ['PATCH', "/api/admin/merchants/{$merchant->id}/status", ['status' => 'disabled']],
            'wallets.index' => ['GET', "/api/admin/merchants/{$merchant->id}/wallets", []],
            'wallets.create' => ['POST', "/api/admin/merchants/{$merchant->id}/wallets", ['coin' => 'ltc', 'wallet' => 'ltc1new']],
            'wallets.update' => ['PUT', "/api/admin/merchants/{$merchant->id}/wallets/{$wallet->id}", ['wallet' => 'bcrt1qupdated']],
            'wallets.delete' => ['DELETE', "/api/admin/merchants/{$merchant->id}/wallets/{$wallet->id}", []],
            'merchant_users.index' => ['GET', '/api/admin/merchant-users', []],
            'merchant_users.create' => ['POST', '/api/admin/merchant-users', [
                'merchant_id' => $merchant->id,
                'name' => 'Created User',
                'email' => 'created-user@example.test',
                'password' => 'password123',
                'role_id' => $role->id,
                'status' => 'active',
            ]],
            'merchant_users.role' => ['PATCH', "/api/admin/merchant-users/{$user->id}/role", ['role_id' => $role->id]],
            'merchant_users.status.disable' => ['PATCH', "/api/admin/merchant-users/{$user->id}/status", ['status' => 'disabled']],
            'invoices.index' => ['GET', '/api/admin/invoices', []],
            'invoices.show' => ['GET', "/api/admin/invoices/{$invoice->id}", []],
            'invoices.refresh' => ['POST', "/api/admin/invoices/{$invoice->id}/refresh", []],
            'webhooks.index' => ['GET', '/api/admin/webhook-deliveries', []],
            'webhooks.show' => ['GET', "/api/admin/webhook-deliveries/{$delivery->id}", []],
            'webhooks.retry' => ['POST', "/api/admin/webhook-deliveries/{$delivery->id}/retry", []],
            'api_keys.index' => ['GET', '/api/admin/merchant-api-keys', []],
            'api_keys.revoke' => ['POST', "/api/admin/merchant-api-keys/{$apiKey->id}/revoke", []],
            default => throw new \LogicException("Unknown admin route case [{$case}]."),
        };
    }

    private function admin(string $role): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => uniqid('admin-', true).'@example.test',
            'password' => 'password123',
            'role' => $role,
            'status' => AdminUser::STATUS_ACTIVE,
        ]);
    }

    private function merchant(): Merchant
    {
        return Merchant::query()->create([
            'name' => 'RBAC Merchant',
            'status' => 'active',
            'fee_percent' => '1.00',
        ]);
    }

    private function merchantUser(Merchant $merchant): MerchantUser
    {
        return MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Merchant User',
            'email' => uniqid('merchant-user-', true).'@example.test',
            'password' => 'password123',
            'role_id' => Role::query()->where('slug', 'merchant.owner')->value('id'),
            'status' => 'active',
        ]);
    }

    private function wallet(Merchant $merchant): SuperWallet
    {
        return SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qrbacwallet',
        ]);
    }

    private function invoice(Merchant $merchant): Invoice
    {
        return Invoice::query()->create([
            'merchant_id' => $merchant->id,
            'public_id' => uniqid('rbac-', false),
            'status' => 'awaiting_asset',
            'expected_usd' => '10.00',
            'expires_at' => now('UTC')->addHour(),
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
            'metadata' => [],
        ]);
    }

    private function delivery(Merchant $merchant, ?Invoice $invoice = null): WebhookDelivery
    {
        $invoice ??= $this->invoice($merchant);

        return WebhookDelivery::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'event' => 'invoice.paid',
            'url' => 'https://merchant.example/webhook',
            'payload' => ['invoice_id' => $invoice->public_id],
            'signature' => 'signature',
            'attempts' => 1,
            'status' => 'failed',
            'last_error' => 'HTTP 500',
        ]);
    }

    private function apiKey(Merchant $merchant): MerchantApiKey
    {
        return MerchantApiKey::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'RBAC key',
            'token_hash' => hash('sha256', uniqid('token-', true)),
        ]);
    }
}
