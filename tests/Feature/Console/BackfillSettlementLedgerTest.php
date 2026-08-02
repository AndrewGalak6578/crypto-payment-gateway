<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSettlementEntry;
use App\Services\Custody\Phase2ACutoverActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_backfill_skips_a_second_completed_internal_credit_key_for_one_invoice(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Existing Credit Merchant',
            'status' => 'active',
            'fee_percent' => '1.50',
        ]);
        $invoice = $this->createInvoice($merchant, [
            'public_id' => 'INV-BACKFILL-CONFLICT',
            'forward_status' => 'done',
            'merchant_payout_coin' => '0.01000000',
            'merchant_payout_usd' => '100.00',
        ]);
        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'settlement_attempt_id' => null,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => '0.01000000',
            'fee_coin' => null,
            'amount_usd' => '100.00',
            'destination_wallet' => null,
            'txid' => null,
            'idempotency_key' => "invoice:{$invoice->id}:internal-credit",
            'error_message' => null,
            'metadata' => [
                'invoice_public_id' => $invoice->public_id,
                'reason' => 'internal_balance_only',
            ],
            'occurred_at' => now('UTC'),
        ]);

        $this->artisan('settlements:backfill-ledger')
            ->expectsOutputToContain("internal_credit_invoice_conflict invoice #{$invoice->id}")
            ->expectsOutputToContain('Created: 0. Skipped: 1')
            ->assertSuccessful();

        self::assertSame(1, MerchantSettlementEntry::query()->count());
        self::assertFalse(MerchantSettlementEntry::query()
            ->where('idempotency_key', "invoice:{$invoice->id}:backfill:internal-credit")
            ->exists());
    }

    public function test_post_cutover_write_fails_and_dry_run_reports_prohibition_without_scanning_or_writing(): void
    {
        $this->setRequiredPhase2AGates();
        app(Phase2ACutoverActivator::class)->activate('phpunit-backfill-cutover-v1');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $merchant = Merchant::query()->create([
            'name' => 'Post Cutover Backfill Merchant',
            'status' => 'active',
            'fee_percent' => '1.50',
        ]);
        $this->createInvoice($merchant, [
            'public_id' => 'INV-POST-CUTOVER-BACKFILL',
            'forward_status' => 'done',
            'merchant_payout_coin' => '0.01000000',
        ]);

        $this->artisan('settlements:backfill-ledger')
            ->expectsOutputToContain('post_cutover_backfill_write_prohibited')
            ->assertFailed();
        self::assertSame(0, MerchantSettlementEntry::query()->count());

        $this->artisan('settlements:backfill-ledger', ['--dry-run' => true])
            ->expectsOutputToContain('post_cutover_backfill_write_prohibited')
            ->assertSuccessful();
        self::assertSame(0, MerchantSettlementEntry::query()->count());
    }

    public function test_pre_cutover_dry_run_is_strictly_read_only(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Dry Run Merchant',
            'status' => 'active',
            'fee_percent' => '1.50',
        ]);
        $invoice = $this->createInvoice($merchant, [
            'public_id' => 'INV-BACKFILL-DRY-RUN',
            'forward_status' => 'done',
            'merchant_payout_coin' => '0.01000000',
        ]);

        $this->artisan('settlements:backfill-ledger', ['--dry-run' => true])
            ->expectsOutputToContain("Would backfill invoice #{$invoice->id}")
            ->expectsOutputToContain('Created: 1')
            ->assertSuccessful();

        self::assertSame(0, MerchantSettlementEntry::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
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

    private function setRequiredPhase2AGates(): void
    {
        config()->set('custody.accounting_enabled', true);
        config()->set('custody.journal_writes_enabled', true);
        config()->set('custody.phase2a_shadow_internal_credits_enabled', true);
        config()->set('custody.invoice_routing_enabled', false);
        config()->set('custody.payout_requests_enabled', false);
        config()->set('custody.payout_automatic_requests_enabled', false);
        config()->set('custody.payout_execution_enabled', false);
    }
}
