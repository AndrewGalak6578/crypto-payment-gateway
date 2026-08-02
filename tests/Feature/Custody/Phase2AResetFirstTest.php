<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\AssetPolicy;
use App\Models\CustodyAccount;
use App\Models\CustodyJournalEntry;
use App\Models\CustodyJournalSourceLink;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementAttempt;
use App\Models\MerchantSettlementEntry;
use App\Models\WebhookDelivery;
use App\Services\Custody\Phase2ACutoverActivator;
use App\Services\Custody\Phase2AInternalCreditProjector;
use App\Services\Custody\Phase2AVerifier;
use App\Services\InvoiceForwarder;
use App\Services\Settlement\SettlementDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class Phase2AResetFirstTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('webhooks.enabled', false);
        config()->set('coins.mode', 'mock');
        $this->setRequiredPhase2AGates();
    }

    public function test_post_cutover_credit_is_atomic_exact_and_replays_without_money_movement(): void
    {
        $marker = $this->activate();
        $invoice = $this->internalCreditInvoice();

        app(InvoiceForwarder::class)->forward($invoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $source = MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->sole();
        $link = CustodyJournalSourceLink::query()->sole();
        $journal = CustodyJournalEntry::query()->with('postings.account')->sole();
        $balance = MerchantBalance::query()->sole();

        self::assertSame('internal_credit_shadow_v1', $marker->phase_key);
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
        self::assertSame('0.490000000000000000', (string) $balance->amount);
        self::assertSame('0.490000000000000000', (string) $source->amount_coin);
        self::assertSame('0.010000000000000000', (string) $source->fee_coin);
        self::assertSame('4900.00', (string) $source->amount_usd);
        self::assertSame('internal_balance_only', $source->metadata['reason']);
        self::assertNull($source->destination_wallet);
        self::assertNull($source->txid);
        self::assertSame($source->id, $link->merchant_settlement_entry_id);
        self::assertSame($journal->id, $link->custody_journal_entry_id);
        self::assertSame(hash('sha256', $link->source_snapshot_canonical_text), $link->source_snapshot_hash);
        $snapshot = json_decode(
            $link->source_snapshot_canonical_text,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame([
            'amount_coin',
            'amount_usd',
            'asset_key',
            'asset_scale',
            'destination_wallet',
            'error_message',
            'fee_coin',
            'id',
            'idempotency_key',
            'invoice_id',
            'merchant_id',
            'metadata',
            'network_key',
            'occurred_at',
            'settlement_attempt_id',
            'source_kind',
            'source_version',
            'status',
            'txid',
            'type',
        ], array_keys($snapshot));
        self::assertSame(['invoice_public_id', 'reason'], array_keys($snapshot['metadata']));
        self::assertSame($source->id, $snapshot['id']);
        self::assertSame($invoice->id, $snapshot['invoice_id']);
        self::assertSame($invoice->public_id, $snapshot['metadata']['invoice_public_id']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D',
            $snapshot['occurred_at'],
        );
        self::assertEquals($snapshot, $link->source_snapshot_jsonb);
        self::assertSame('internal_credit_shadow_v1', $journal->event_type);
        self::assertSame("merchant_settlement_entry:{$source->id}", $journal->source_reference);
        self::assertCount(2, $journal->postings);
        self::assertSame(
            [CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET, CustodyAccount::CODE_MERCHANT_AVAILABLE],
            $journal->postings->pluck('account.account_code')->all(),
        );
        self::assertSame(['debit', 'credit'], $journal->postings->pluck('side')->all());
        self::assertSame(['49000000', '49000000'], $journal->postings->pluck('amount_atomic')->all());
        self::assertSame(0, MerchantSettlementAttempt::query()->count());
        self::assertSame(0, WebhookDelivery::query()->count());
        self::assertTrue(app(Phase2AVerifier::class)->verify()['clean']);

        $before = $this->financialCounts();
        $projectionBefore = DB::table('custody_account_balances')->orderBy('account_id')->get()->toArray();
        app(InvoiceForwarder::class)->forward($invoice->id);
        self::assertSame($before, $this->financialCounts());
        self::assertEquals($projectionBefore, DB::table('custody_account_balances')->orderBy('account_id')->get()->toArray());

        $replayed = app(Phase2AInternalCreditProjector::class)->replayExact($source->id);
        self::assertSame($journal->id, $replayed->id);
        self::assertSame($before, $this->financialCounts());
        self::assertEquals($projectionBefore, DB::table('custody_account_balances')->orderBy('account_id')->get()->toArray());
    }

    public function test_pre_cutover_shadow_off_preserves_legacy_only_credit(): void
    {
        config()->set('custody.accounting_enabled', false);
        config()->set('custody.journal_writes_enabled', false);
        config()->set('custody.phase2a_shadow_internal_credits_enabled', false);
        $invoice = $this->internalCreditInvoice();

        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertSame('0.490000000000000000', (string) MerchantBalance::query()->sole()->amount);
        self::assertSame(1, MerchantSettlementEntry::query()->count());
        self::assertSame(0, CustodyAccount::query()->count());
        self::assertSame(0, CustodyJournalEntry::query()->count());
        self::assertSame(0, CustodyJournalSourceLink::query()->count());
    }

    public function test_shadow_on_before_cutover_and_invalid_post_cutover_gates_fail_without_writes(): void
    {
        config()->set('custody.phase2a_shadow_internal_credits_enabled', true);
        $preCutoverInvoice = $this->internalCreditInvoice();

        try {
            app(InvoiceForwarder::class)->forward($preCutoverInvoice->id);
            self::fail('Expected pre-cutover shadow gate failure.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('before immutable cutover', $e->getMessage());
        }
        self::assertSame(0, MerchantBalance::query()->count());
        self::assertSame(0, MerchantSettlementEntry::query()->count());

        $preCutoverInvoice->delete();
        AssetPolicy::query()->delete();
        $this->activate();
        config()->set('custody.phase2a_shadow_internal_credits_enabled', false);
        $postCutoverInvoice = $this->internalCreditInvoice();

        try {
            app(InvoiceForwarder::class)->forward($postCutoverInvoice->id);
            self::fail('Expected post-cutover fail-closed gate failure.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('failed closed', $e->getMessage());
        }

        self::assertSame(0, MerchantBalance::query()->count());
        self::assertSame(0, MerchantSettlementEntry::query()->count());
        self::assertSame(0, CustodyJournalEntry::query()->count());
    }

    public function test_non_boolean_gate_blocks_activation_and_post_cutover_credit_before_financial_writes(): void
    {
        config()->set('custody.payout_requests_enabled', 'flase');
        $beforeActivation = $this->financialCounts();

        try {
            app(Phase2ACutoverActivator::class)->activate('invalid-non-boolean-gate');
            self::fail('A non-Boolean gate must block Phase 2A activation.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('must be a Boolean', $e->getMessage());
        }

        self::assertSame($beforeActivation, $this->financialCounts());

        $this->setRequiredPhase2AGates();
        $this->activate();
        $invoice = $this->internalCreditInvoice();
        config()->set('custody.payout_requests_enabled', 'flase');
        $beforeCredit = $this->financialCounts();

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('A non-Boolean gate must block a post-cutover positive credit.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('must be a Boolean', $e->getMessage());
        }

        self::assertSame($beforeCredit, $this->financialCounts());
        self::assertSame(Invoice::FORWARD_STATUS_NONE, $invoice->fresh()->forward_status);
    }

    public function test_guard_failure_rolls_back_source_journal_link_balance_invoice_and_webhook(): void
    {
        $this->activate();
        $invoice = $this->internalCreditInvoice();
        MerchantBalance::query()->create([
            'merchant_id' => $invoice->merchant_id,
            'coin' => 'btc',
            'amount' => '1.00000000',
        ]);

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('Expected Phase 2A parity guard rejection.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('parity guard failed', $e->getMessage());
        }

        self::assertSame(Invoice::FORWARD_STATUS_NONE, $invoice->fresh()->forward_status);
        self::assertSame('1.000000000000000000', (string) MerchantBalance::query()->sole()->amount);
        self::assertSame(0, MerchantSettlementEntry::query()->count());
        self::assertSame(0, CustodyJournalEntry::query()->count());
        self::assertSame(0, CustodyJournalSourceLink::query()->count());
        self::assertSame(0, DB::table('custody_journal_postings')->count());
        self::assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_guard_blocks_merchant_projection_and_offset_drift_before_business_mutation(): void
    {
        $this->activate();
        $firstInvoice = $this->internalCreditInvoice();
        app(InvoiceForwarder::class)->forward($firstInvoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $merchant = Merchant::query()->findOrFail($firstInvoice->merchant_id);

        foreach ([
            CustodyAccount::CODE_MERCHANT_AVAILABLE => 'merchant projection versus covered source total',
            CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET => 'offset projection versus aggregate covered liability',
        ] as $accountCode => $expectedFailure) {
            DB::beginTransaction();

            try {
                $account = CustodyAccount::query()->where('account_code', $accountCode)->sole();
                DB::table('custody_account_balances')->where('account_id', $account->id)->update([
                    'balance' => '0.48000000',
                ]);
                $secondInvoice = $this->createInternalCreditInvoiceForMerchant($merchant);

                try {
                    app(InvoiceForwarder::class)->forward($secondInvoice->id);
                    self::fail('Phase 2A guard must reject a drifted projection.');
                } catch (CustodyAccountingException $e) {
                    self::assertStringContainsString($expectedFailure, $e->getMessage());
                }

                self::assertSame(Invoice::FORWARD_STATUS_NONE, $secondInvoice->fresh()->forward_status);
                self::assertFalse(MerchantSettlementEntry::query()
                    ->where('invoice_id', $secondInvoice->id)
                    ->exists());
                self::assertSame(
                    '0.490000000000000000',
                    (string) MerchantBalance::query()->where('merchant_id', $merchant->id)->sole()->amount,
                );
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_retryable_invoice_with_pre_existing_source_conflicts_without_balance_increment(): void
    {
        config()->set('custody.accounting_enabled', false);
        config()->set('custody.journal_writes_enabled', false);
        config()->set('custody.phase2a_shadow_internal_credits_enabled', false);
        $invoice = $this->internalCreditInvoice();
        MerchantSettlementEntry::query()->create([
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'settlement_attempt_id' => null,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => '0.49000000',
            'fee_coin' => '0.01000000',
            'amount_usd' => '4900.00',
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

        $this->expectException(CustodyIdempotencyConflictException::class);

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
        } finally {
            self::assertSame(0, MerchantBalance::query()->count());
            self::assertSame(Invoice::FORWARD_STATUS_NONE, $invoice->fresh()->forward_status);
            self::assertSame(1, MerchantSettlementEntry::query()->count());
        }
    }

    public function test_zero_remaining_amount_creates_no_money_or_shadow_rows(): void
    {
        $this->activate();
        $invoice = $this->internalCreditInvoice([
            'merchant_payout_coin' => '0.00000000',
            'fee_coin' => '0.50000000',
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);

        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
        self::assertSame(0, MerchantBalance::query()->count());
        self::assertSame(0, MerchantSettlementEntry::query()->count());
        self::assertSame(0, CustodyAccount::query()->count());
        self::assertSame(0, CustodyJournalEntry::query()->count());
        self::assertSame(0, CustodyJournalSourceLink::query()->count());
    }

    public function test_decimal_contract_accepts_zero_tails_and_rejects_non_zero_excess_precision(): void
    {
        $decimal = app(SettlementDecimal::class);

        self::assertSame('0.49000000', $decimal->formatExact('0.490000000000000000', 'btc'));
        self::assertSame('0.01000000', $decimal->formatExact('0.010000000000000000', 'btc'));
        self::assertSame('49000000', $decimal->atomicExact('0.490000000000000000', 'btc'));
        self::assertSame('4900.00', $decimal->usdExact('4900.000000000000000000'));
        self::assertSame('1.24', $decimal->usd('0.00012350', '10000'));

        foreach ([
            fn () => $decimal->formatExact('0.490000001', 'btc'),
            fn () => $decimal->formatExact('0.010000001', 'btc'),
            fn () => $decimal->usdExact('1.230000000000000001'),
        ] as $invalidExactValue) {
            try {
                $invalidExactValue();
                self::fail('A non-zero digit beyond the exact scale must be rejected.');
            } catch (CustodyAccountingException $e) {
                self::assertStringContainsString('non-zero precision', $e->getMessage());
            }
        }
    }

    public function test_live_credit_rejects_raw_locked_amount_precision_before_any_financial_write(): void
    {
        $this->activate();
        $invoice = $this->internalCreditInvoice([
            'merchant_payout_coin' => '0.490000001',
        ]);

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('A raw locked payout with non-zero excess precision must fail closed.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('non-zero precision', $e->getMessage());
        }

        self::assertSame(Invoice::FORWARD_STATUS_NONE, $invoice->fresh()->forward_status);
        self::assertSame([
            'balances' => 0,
            'sources' => 0,
            'accounts' => 0,
            'journals' => 0,
            'postings' => 0,
            'links' => 0,
            'webhooks' => 0,
        ], $this->financialCounts());
    }

    public function test_live_credit_rejects_non_zero_excess_precision_in_previous_completed_fee_before_any_new_write(): void
    {
        $this->activate();
        $invoice = $this->internalCreditInvoice();
        $previous = MerchantSettlementEntry::query()->create([
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'settlement_attempt_id' => null,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'amount_coin' => '0.01000000',
            'fee_coin' => '0.000000001000000000',
            'amount_usd' => '100.00',
            'destination_wallet' => 'bcrt1qphase2apreviousforward',
            'txid' => 'phase2a-previous-forward-txid',
            'idempotency_key' => "invoice:{$invoice->id}:forward:previous",
            'error_message' => null,
            'metadata' => ['test' => 'excess_previous_fee_precision'],
            'occurred_at' => now('UTC'),
        ]);
        $before = $this->financialCounts();

        try {
            app(InvoiceForwarder::class)->forward($invoice->id);
            self::fail('A previous completed BTC fee with non-zero excess precision must fail closed.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('non-zero precision', $e->getMessage());
        }

        self::assertSame($before, $this->financialCounts());
        self::assertSame('0.000000001000000000', $previous->fresh()->getRawOriginal('fee_coin'));
        self::assertSame(Invoice::FORWARD_STATUS_NONE, $invoice->fresh()->forward_status);
        self::assertSame(0, MerchantBalance::query()->count());
        self::assertSame(0, CustodyAccount::query()->count());
        self::assertSame(0, CustodyJournalEntry::query()->count());
        self::assertSame(0, CustodyJournalSourceLink::query()->count());
        self::assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_nullable_usd_snapshot_is_accepted_without_rounding_on_replay(): void
    {
        $this->activate();
        $invoice = $this->internalCreditInvoice([
            'rate_usd' => '0.00',
            'merchant_payout_usd' => null,
        ]);

        app(InvoiceForwarder::class)->forward($invoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $source = MerchantSettlementEntry::query()->sole();
        $snapshot = CustodyJournalSourceLink::query()->sole()->source_snapshot_jsonb;
        self::assertNull($source->amount_usd);
        self::assertNull($snapshot['amount_usd']);
        self::assertSame(
            CustodyJournalEntry::query()->sole()->id,
            app(Phase2AInternalCreditProjector::class)->replayExact($source->id)->id,
        );
    }

    private function activate()
    {
        $marker = app(Phase2ACutoverActivator::class)->activate('phpunit-reset-first-v1');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $marker;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function internalCreditInvoice(array $overrides = []): Invoice
    {
        $merchant = $this->createMerchant(['fee_percent' => '2.00']);
        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);

        return $this->createInternalCreditInvoiceForMerchant($merchant, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInternalCreditInvoiceForMerchant(
        Merchant $merchant,
        array $overrides = [],
    ): Invoice {
        return $this->createInvoice($merchant, array_merge([
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => '0.50000000',
            'received_all_coin' => '0.50000000',
            'fee_coin' => '0.01000000',
            'merchant_payout_coin' => '0.49000000',
            'merchant_payout_usd' => '4900.00',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forwarded_coin' => '0.00000000',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
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

    /**
     * @return array<string, int>
     */
    private function financialCounts(): array
    {
        return [
            'balances' => MerchantBalance::query()->count(),
            'sources' => MerchantSettlementEntry::query()->count(),
            'accounts' => CustodyAccount::query()->count(),
            'journals' => CustodyJournalEntry::query()->count(),
            'postings' => DB::table('custody_journal_postings')->count(),
            'links' => CustodyJournalSourceLink::query()->count(),
            'webhooks' => WebhookDelivery::query()->count(),
        ];
    }
}
