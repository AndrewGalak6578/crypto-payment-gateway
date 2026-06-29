<?php

declare(strict_types=1);

namespace Tests\Feature\Api\MerchantPortal;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSettlementEntry;
use App\Models\MerchantUser;
use App\Models\Role;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantSettlementEntryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(MerchantAccessSeeder::class);
    }

    public function test_merchant_can_view_own_settlement_entries(): void
    {
        $merchant = $this->createMerchant('Settlement Merchant');
        $otherMerchant = $this->createMerchant('Other Merchant');
        $viewer = $this->createMerchantUser($merchant, 'merchant.owner', 'settlements-owner@example.test');
        $invoice = $this->createInvoice($merchant, ['public_id' => 'INV-SETTLED-1']);
        $otherInvoice = $this->createInvoice($otherMerchant, ['public_id' => 'INV-OTHER-1']);

        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => '0.010000000000000000',
            'fee_coin' => '0.000100000000000000',
            'amount_usd' => '100.00',
            'destination_wallet' => 'bcrt1qmerchantdestination',
            'txid' => 'tx_merchant_forward',
            'idempotency_key' => 'test:merchant:forward',
            'occurred_at' => now('UTC'),
        ]);

        MerchantSettlementEntry::query()->create([
            'merchant_id' => $otherMerchant->id,
            'invoice_id' => $otherInvoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => '0.020000000000000000',
            'idempotency_key' => 'test:other:forward',
            'occurred_at' => now('UTC'),
        ]);

        $this->actingAs($viewer, 'merchant');

        $response = $this->getJson('/api/merchant/settlement-entries');

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.txid', 'tx_merchant_forward')
            ->assertJsonPath('data.data.0.invoice.public_id', 'INV-SETTLED-1')
            ->assertJsonMissing(['INV-OTHER-1']);
    }

    public function test_settlement_entries_can_be_filtered_by_status(): void
    {
        $merchant = $this->createMerchant('Settlement Filter Merchant');
        $viewer = $this->createMerchantUser($merchant, 'merchant.owner', 'settlements-filter-owner@example.test');
        $invoice = $this->createInvoice($merchant, ['public_id' => 'INV-FILTER-1']);

        foreach ([MerchantSettlementEntry::STATUS_COMPLETED, MerchantSettlementEntry::STATUS_FAILED] as $status) {
            MerchantSettlementEntry::query()->create([
                'merchant_id' => $merchant->id,
                'invoice_id' => $invoice->id,
                'asset_key' => 'dash',
                'network_key' => 'dash',
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => $status,
                'amount_coin' => '1.000000000000000000',
                'idempotency_key' => "test:filter:{$status}",
                'occurred_at' => now('UTC'),
            ]);
        }

        $this->actingAs($viewer, 'merchant');

        $response = $this->getJson('/api/merchant/settlement-entries?status=failed');

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.status', MerchantSettlementEntry::STATUS_FAILED);
    }

    private function createMerchant(string $name): Merchant
    {
        return Merchant::query()->create([
            'name' => $name,
            'status' => 'active',
            'fee_percent' => 1.5,
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createInvoice(Merchant $merchant, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'merchant_id' => $merchant->id,
            'public_id' => 'INV-'.strtoupper(substr(md5((string) microtime(true)), 0, 10)),
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'pay_address' => 'bcrt1qdeposit',
            'amount_coin' => '0.01000000',
            'expected_usd' => '100.00',
            'rate_usd' => '10000.00',
            'received_conf_coin' => '0.01000000',
            'received_all_coin' => '0.01000000',
            'forward_status' => 'done',
            'expires_at' => now()->addHour(),
            'metadata' => [],
        ], $overrides));
    }
}
