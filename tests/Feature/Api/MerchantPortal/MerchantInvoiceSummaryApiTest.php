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

final class MerchantInvoiceSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_summary_returns_real_status_counts_and_virtual_partial_filter(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Merchant A',
            'status' => 'active',
            'fee_percent' => 2.00,
        ]);

        $owner = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'password123',
            'role_id' => (int) Role::query()->where('slug', 'merchant.owner')->value('id'),
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'merchant');

        $this->createInvoice($merchant, 'INV-PAID', 'paid', receivedAll: '10.00000000');
        $this->createInvoice($merchant, 'INV-AWAITING-ASSET', 'awaiting_asset');
        $this->createInvoice($merchant, 'INV-PENDING', 'pending');
        $this->createInvoice($merchant, 'INV-PARTIAL', 'pending', receivedAll: '4.00000000');
        $this->createInvoice($merchant, 'INV-EXPIRED', 'expired');
        $this->createInvoice($merchant, 'INV-BTC', 'paid', receivedAll: '10.00000000', assetKey: 'btc', coin: 'BTC', networkKey: 'bitcoin');

        $summaryResponse = $this->getJson('/api/merchant/invoices/summary');

        $summaryResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.paid', 2)
            ->assertJsonPath('data.awaiting_asset', 1)
            ->assertJsonPath('data.pending', 2)
            ->assertJsonPath('data.partial', 1)
            ->assertJsonPath('data.expired', 1);

        $partialResponse = $this->getJson('/api/merchant/invoices?status=partial');

        $partialResponse->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.public_id', 'INV-PARTIAL');

        $awaitingResponse = $this->getJson('/api/merchant/invoices?status=awaiting');

        $awaitingResponse->assertOk()
            ->assertJsonPath('data.total', 3);

        $multiAssetResponse = $this->getJson('/api/merchant/invoices?coin=dash,btc&per_page=2');

        $multiAssetResponse->assertOk()
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonCount(2, 'data.data');
    }

    private function createInvoice(
        Merchant $merchant,
        string $publicId,
        string $status,
        string $receivedAll = '0.00000000',
        string $assetKey = 'dash',
        string $coin = 'DASH',
        string $networkKey = 'dash'
    ): void {
        Invoice::query()->create([
            'merchant_id' => $merchant->id,
            'public_id' => $publicId,
            'external_id' => strtolower($publicId),
            'status' => $status,
            'coin' => $coin,
            'asset_key' => $assetKey,
            'network_key' => $networkKey,
            'pay_address' => "X{$publicId}",
            'amount_coin' => '10.00000000',
            'expected_usd' => '10.00',
            'rate_usd' => '1.00',
            'received_conf_coin' => $receivedAll,
            'received_all_coin' => $receivedAll,
            'forward_status' => 'none',
            'expires_at' => now()->addHour(),
        ]);
    }
}
