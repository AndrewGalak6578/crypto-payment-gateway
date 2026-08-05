<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Data\CustodyPostingData;
use App\Exceptions\CustodyAccountingException;
use App\Models\AssetPolicy;
use App\Models\CustodyAccount;
use App\Models\CustodyPhase2ACutover;
use App\Models\Invoice;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementEntry;
use App\Services\Custody\CustodyAccountRepository;
use App\Services\Custody\CustodyCanonicalPayload;
use App\Services\Custody\CustodyJournalWriter;
use App\Services\Custody\Phase2ACutoverActivator;
use App\Services\Custody\Phase2AGate;
use App\Services\Custody\Phase2AVerifier;
use App\Services\InvoiceForwarder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class Phase2AVerifierTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableForwardingForTests('phase2a_existing_contract');
        config()->set('webhooks.enabled', false);
        $this->setRequiredPhase2AGates();
    }

    public function test_zero_baseline_and_post_credit_verification_are_clean_and_read_only(): void
    {
        $before = $this->tableCounts();
        $zero = app(Phase2AVerifier::class)->verify();
        self::assertTrue($zero['clean']);
        self::assertSame([
            'completed_internal_credit_count' => 0,
            'covered_internal_credit_count' => 0,
            'projection_drift_count' => 0,
            'source_snapshot_mutation_count' => 0,
            'uncovered_internal_credit_count' => 0,
            'unexplained_legacy_residual_count' => 0,
        ], $zero['parity']);
        self::assertSame($before, $this->tableCounts());

        $this->activateAndValidate();
        $invoice = $this->validInternalCreditInvoice();
        app(InvoiceForwarder::class)->forward($invoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $beforeVerify = $this->tableCounts();

        $report = app(Phase2AVerifier::class)->verify();

        self::assertTrue($report['clean']);
        self::assertSame(1, $report['parity']['completed_internal_credit_count']);
        self::assertSame(1, $report['parity']['covered_internal_credit_count']);
        self::assertSame('0.490000000000000000', $report['merchant_scope_rows'][0]['merchant_balance']);
        self::assertSame('0.490000000000000000', $report['offset_scope_rows'][0]['offset_projection']);
        self::assertSame($beforeVerify, $this->tableCounts());
        $this->artisan('custody:verify-phase2a', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"issue_count": 0');
    }

    public function test_verifier_reports_balance_residual_offset_and_projection_drift_without_repair(): void
    {
        $this->activateAndValidate();
        $invoice = $this->validInternalCreditInvoice();
        app(InvoiceForwarder::class)->forward($invoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        MerchantBalance::query()->where('merchant_id', $invoice->merchant_id)->update([
            'amount' => '0.50000000',
        ]);
        $available = CustodyAccount::query()
            ->where('account_code', CustodyAccount::CODE_MERCHANT_AVAILABLE)
            ->sole();
        DB::table('custody_account_balances')->where('account_id', $available->id)->update([
            'balance' => '0.48000000',
        ]);
        $before = $this->tableCounts();

        $report = app(Phase2AVerifier::class)->verify();

        self::assertFalse($report['clean']);
        self::assertGreaterThan(0, $report['issue_count']);
        self::assertSame(1, $report['parity']['projection_drift_count']);
        self::assertSame(1, $report['parity']['unexplained_legacy_residual_count']);
        self::assertSame('0.010000000000000000', $report['merchant_scope_rows'][0]['unexplained_legacy_residual']);
        self::assertSame($before, $this->tableCounts());
        $this->artisan('custody:verify-phase2a')->assertFailed();
    }

    public function test_generic_merchant_available_journal_without_balance_or_source_is_reported_from_account_scope(): void
    {
        $before = $this->tableCounts();
        DB::beginTransaction();

        try {
            $merchant = $this->createMerchant();
            $accounts = app(CustodyAccountRepository::class);
            $treasury = $accounts->platform(
                'btc',
                'bitcoin',
                CustodyAccount::CODE_TREASURY_AVAILABLE,
            );
            $available = $accounts->merchant(
                $merchant->id,
                'btc',
                'bitcoin',
                CustodyAccount::CODE_MERCHANT_AVAILABLE,
            );
            app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                idempotencyKey: 'custody:test:generic-merchant-available-negative-control',
                eventType: 'generic_non_phase2a_posted_event',
                assetKey: 'btc',
                networkKey: 'bitcoin',
                merchantId: $merchant->id,
                postings: [
                    new CustodyPostingData($treasury->id, 'debit', '0.01000000', '1000000'),
                    new CustodyPostingData($available->id, 'credit', '0.01000000', '1000000'),
                ],
            ));

            self::assertSame(0, MerchantBalance::query()->count());
            self::assertSame(0, MerchantSettlementEntry::query()->count());
            self::assertSame(0, DB::table('custody_journal_source_links')->count());

            $report = app(Phase2AVerifier::class)->verify();
            $scope = collect($report['merchant_scope_rows'])->first(
                fn (array $row): bool => $row['merchant_id'] === $merchant->id
                    && $row['asset_key'] === 'btc'
                    && $row['network_key'] === 'bitcoin',
            );

            self::assertFalse($report['clean']);
            self::assertGreaterThan(0, $report['issue_count']);
            self::assertNotNull($scope);
            self::assertSame('0.010000000000000000', $scope['merchant_available_projection']);
            self::assertSame('0.000000000000000000', $scope['valid_covered_source_total']);
            self::assertFalse($scope['journal_matches_valid_covered_total']);
        } finally {
            DB::rollBack();
        }

        self::assertSame($before, $this->tableCounts());
    }

    public function test_cutover_marker_hash_payload_and_exact_replay_are_immutable_and_runtime_free(): void
    {
        $this->activateAndValidate();
        $marker = CustodyPhase2ACutover::query()->sole();
        $original = $marker->getRawOriginal();
        $baseline = json_decode($marker->baseline_verification_canonical_text, true, flags: JSON_THROW_ON_ERROR);
        $config = json_decode($marker->activation_config_canonical_text, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(hash('sha256', $marker->baseline_verification_canonical_text), $marker->baseline_verification_hash);
        self::assertSame(hash('sha256', $marker->activation_config_canonical_text), $marker->activation_config_fingerprint);
        self::assertSame('custody_phase2a_zero_parity_baseline_v1', $baseline['baseline_schema_version']);
        self::assertArrayNotHasKey('activated_at', $baseline);
        self::assertArrayNotHasKey('timestamp', $baseline);
        self::assertSame(Phase2AGate::requiredActivationConfig(), $config);

        config()->set('custody.accounting_enabled', false);
        config()->set('custody.phase2a_shadow_internal_credits_enabled', false);
        config()->set('custody.invoice_routing_enabled', true);
        $replay = app(Phase2ACutoverActivator::class)->activate('different-reference-must-not-write');

        self::assertSame($marker->phase_key, $replay->phase_key);
        self::assertSame($original, $replay->fresh()->getRawOriginal());
    }

    public function test_activation_requires_exact_gate_configuration(): void
    {
        foreach ([
            'custody.accounting_enabled',
            'custody.journal_writes_enabled',
            'custody.phase2a_shadow_internal_credits_enabled',
        ] as $requiredTrueGate) {
            $this->setRequiredPhase2AGates();
            config()->set($requiredTrueGate, false);
            try {
                app(Phase2ACutoverActivator::class)->activate("invalid-{$requiredTrueGate}");
                self::fail("Expected {$requiredTrueGate} activation failure.");
            } catch (CustodyAccountingException $e) {
                self::assertStringContainsString('exact seven-gate', $e->getMessage());
            }
        }

        foreach ([
            'custody.invoice_routing_enabled',
            'custody.payout_requests_enabled',
            'custody.payout_automatic_requests_enabled',
            'custody.payout_execution_enabled',
        ] as $requiredFalseGate) {
            $this->setRequiredPhase2AGates();
            config()->set($requiredFalseGate, true);
            try {
                app(Phase2ACutoverActivator::class)->activate("invalid-{$requiredFalseGate}");
                self::fail("Expected {$requiredFalseGate} activation failure.");
            } catch (CustodyAccountingException $e) {
                self::assertStringContainsString('exact seven-gate', $e->getMessage());
            }
        }

        $this->setRequiredPhase2AGates();
        config()->set('custody.payout_requests_enabled', 'false');
        try {
            app(Phase2ACutoverActivator::class)->activate('invalid-non-boolean-gate');
            self::fail('A non-Boolean activation gate must be rejected.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('must be a Boolean', $e->getMessage());
        }
    }

    public function test_database_rejects_missing_extra_and_non_boolean_activation_config_fields(): void
    {
        $canonical = app(CustodyCanonicalPayload::class);
        $baselinePayload = $this->exactBaselinePayload();
        $baselineText = $canonical->json($baselinePayload);
        $validConfig = Phase2AGate::requiredActivationConfig();
        $variants = [
            array_diff_key($validConfig, ['payout_requests_enabled' => true]),
            [...$validConfig, 'extra_gate' => false],
            [...$validConfig, 'payout_requests_enabled' => 'false'],
        ];

        foreach ($variants as $index => $variant) {
            $configText = $canonical->json($variant);
            DB::beginTransaction();
            try {
                DB::table('custody_phase2a_cutovers')->insert([
                    'phase_key' => CustodyPhase2ACutover::PHASE_KEY,
                    'activated_at' => now('UTC'),
                    'activation_reference' => "bad-config-{$index}",
                    'baseline_verification_canonical_text' => $baselineText,
                    'baseline_verification_hash' => hash('sha256', $baselineText),
                    'activation_config_canonical_text' => $configText,
                    'activation_config_fingerprint' => hash('sha256', $configText),
                    'created_at' => now('UTC'),
                ]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                self::fail('Expected exact activation config rejection.');
            } catch (QueryException $e) {
                self::assertStringContainsString('exact approved baseline/config', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_activation_rejects_dirty_legacy_balance_settlement_and_custody_baselines(): void
    {
        $merchant = $this->createMerchant();
        MerchantBalance::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'amount' => '1.00000000',
        ]);
        $this->assertActivationRejectedAsDirty();
    }

    public function test_activation_rejects_dirty_settlement_source_baseline(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, ['status' => 'paid']);
        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_HELD,
            'status' => MerchantSettlementEntry::STATUS_DEFERRED,
            'amount_coin' => '1.00000000',
            'idempotency_key' => "invoice:{$invoice->id}:dirty-hold",
            'metadata' => [],
            'occurred_at' => now('UTC'),
        ]);
        $this->assertActivationRejectedAsDirty();
    }

    public function test_activation_rejects_dirty_custody_account_and_projection_baseline(): void
    {
        app(CustodyAccountRepository::class)->platform(
            'btc',
            'bitcoin',
            CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET,
        );
        $this->assertActivationRejectedAsDirty();
    }

    private function assertActivationRejectedAsDirty(): void
    {
        try {
            app(Phase2ACutoverActivator::class)->activate('dirty-baseline');
            self::fail('Expected dirty baseline activation rejection.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('exact zero/parity baseline', $e->getMessage());
        }
        self::assertSame(0, CustodyPhase2ACutover::query()->count());
    }

    private function activateAndValidate(): void
    {
        app(Phase2ACutoverActivator::class)->activate('phpunit-verifier-v1');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function validInternalCreditInvoice(): Invoice
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

        return $this->createInvoice($merchant, [
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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function exactBaselinePayload(): array
    {
        return [
            'baseline_schema_version' => 'custody_phase2a_zero_parity_baseline_v1',
            'counts' => [
                'custody_account_balances' => 0,
                'custody_accounts' => 0,
                'custody_journal_entries' => 0,
                'custody_journal_postings' => 0,
                'custody_journal_source_links' => 0,
                'custody_phase2a_cutovers' => 0,
                'merchant_balances' => 0,
                'merchant_settlement_entries' => 0,
            ],
            'parity' => [
                'completed_internal_credit_count' => 0,
                'covered_internal_credit_count' => 0,
                'projection_drift_count' => 0,
                'source_snapshot_mutation_count' => 0,
                'uncovered_internal_credit_count' => 0,
                'unexplained_legacy_residual_count' => 0,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'merchant_balances' => DB::table('merchant_balances')->count(),
            'merchant_settlement_entries' => DB::table('merchant_settlement_entries')->count(),
            'custody_accounts' => DB::table('custody_accounts')->count(),
            'custody_journal_entries' => DB::table('custody_journal_entries')->count(),
            'custody_journal_postings' => DB::table('custody_journal_postings')->count(),
            'custody_account_balances' => DB::table('custody_account_balances')->count(),
            'custody_journal_source_links' => DB::table('custody_journal_source_links')->count(),
            'custody_phase2a_cutovers' => DB::table('custody_phase2a_cutovers')->count(),
        ];
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
