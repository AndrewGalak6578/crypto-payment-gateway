<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSettlementEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackfillSettlementLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_settlement_entries_from_invoice_summaries_idempotently(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Backfill Merchant',
            'status' => 'active',
            'fee_percent' => 1.5,
        ]);

        $forwarded = $this->createInvoice($merchant, [
            'public_id' => 'INV-BACKFILL-FWD',
            'forward_status' => 'done',
            'forwarded_coin' => '0.01000000',
            'forward_txids' => ['tx_backfill_1'],
            'last_forwarded_at' => now('UTC')->subMinute(),
        ]);

        $internal = $this->createInvoice($merchant, [
            'public_id' => 'INV-BACKFILL-INTERNAL',
            'forward_status' => 'done',
            'merchant_payout_coin' => '0.000064270000000000',
            'merchant_payout_usd' => '0.64',
            'forward_txids' => null,
        ]);

        $this->artisan('settlements:backfill-ledger')
            ->expectsOutputToContain('Created: 2')
            ->assertSuccessful();

        $this->assertDatabaseHas('merchant_settlement_entries', [
            'invoice_id' => $forwarded->id,
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'txid' => 'tx_backfill_1',
        ]);

        $this->assertDatabaseHas('merchant_settlement_entries', [
            'invoice_id' => $internal->id,
            'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => '0.000064270000000000',
        ]);

        $this->artisan('settlements:backfill-ledger')
            ->expectsOutputToContain('Created: 0')
            ->assertSuccessful();

        self::assertSame(2, MerchantSettlementEntry::query()->count());
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
            'forward_status' => 'none',
            'expires_at' => now()->addHour(),
            'metadata' => [],
        ], $overrides));
    }
}
