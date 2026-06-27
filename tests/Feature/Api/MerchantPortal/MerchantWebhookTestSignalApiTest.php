<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Jobs\DeliverWebhookJob;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MerchantWebhookTestSignalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_owner_can_queue_merchant_level_test_webhook(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant([
            'webhook_url' => 'https://merchant.example/webhook',
            'webhook_secret' => 'secret',
        ]);
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-webhook-test@example.test');

        $this->actingAs($owner, 'merchant');

        $response = $this->postJson('/api/merchant/webhook-deliveries/test');

        $response->assertAccepted()
            ->assertJsonPath('data.event', 'merchant.webhook_test')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.url', 'https://merchant.example/webhook');

        $this->assertDatabaseHas('webhook_deliveries', [
            'merchant_id' => $merchant->id,
            'invoice_id' => null,
            'event' => 'merchant.webhook_test',
            'status' => 'pending',
        ]);

        Queue::assertPushed(DeliverWebhookJob::class);
    }

    public function test_test_webhook_requires_configured_endpoint_and_secret(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant();
        $owner = $this->createMerchantUser($merchant, 'merchant.owner', 'owner-webhook-missing@example.test');

        $this->actingAs($owner, 'merchant');

        $response = $this->postJson('/api/merchant/webhook-deliveries/test');

        $response->assertUnprocessable();
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_user_without_webhook_write_cannot_queue_test_webhook(): void
    {
        Queue::fake();

        $merchant = $this->createMerchant([
            'webhook_url' => 'https://merchant.example/webhook',
            'webhook_secret' => 'secret',
        ]);
        $analyst = $this->createMerchantUser($merchant, 'merchant.analyst', 'analyst-webhook-test@example.test');

        $this->actingAs($analyst, 'merchant');

        $response = $this->postJson('/api/merchant/webhook-deliveries/test');

        $response->assertForbidden();
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createMerchant(array $overrides = []): Merchant
    {
        return Merchant::query()->create(array_merge([
            'name' => 'Webhook Test Merchant',
            'status' => 'active',
            'fee_percent' => 2.00,
            'webhook_url' => null,
            'webhook_secret' => null,
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
