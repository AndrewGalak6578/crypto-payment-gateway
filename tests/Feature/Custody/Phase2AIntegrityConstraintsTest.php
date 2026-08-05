<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Data\CustodyPostingData;
use App\Exceptions\CustodyAccountingException;
use App\Models\AssetPolicy;
use App\Models\CustodyAccount;
use App\Models\CustodyJournalEntry;
use App\Models\CustodyJournalSourceLink;
use App\Models\CustodyPhase2ACutover;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementEntry;
use App\Services\Custody\CustodyAccountRepository;
use App\Services\Custody\CustodyJournalWriter;
use App\Services\Custody\Phase2ACutoverActivator;
use App\Services\Custody\Phase2AInternalCreditProjector;
use App\Services\Custody\Phase2ASourceSnapshot;
use App\Services\Custody\Phase2AVerifier;
use App\Services\InvoiceForwarder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class Phase2AIntegrityConstraintsTest extends TestCase
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

    public function test_postgresql_capability_canonical_json_hash_schema_and_deferred_triggers_are_exact(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame(
            'sha256(bytea)',
            DB::selectOne("SELECT to_regprocedure('sha256(bytea)')::text AS value")->value,
        );

        $input = '{"z":"é","nested":{"z":true,"a":[3,2,1]},"a":1}';
        $expectedCanonical = '{"a":1,"nested":{"a":[3,2,1],"z":true},"z":"é"}';
        $row = DB::selectOne(
            "SELECT custody_phase2a_canonical_json_text(CAST(? AS jsonb)) AS canonical,
                    encode(sha256(convert_to(?, 'UTF8')), 'hex') AS hash",
            [$input, $expectedCanonical],
        );
        self::assertSame($expectedCanonical, $row->canonical);
        self::assertSame(hash('sha256', $expectedCanonical), $row->hash);

        $constraints = DB::table('pg_constraint as constraint')
            ->join('pg_class as relation', 'relation.oid', '=', 'constraint.conrelid')
            ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
            ->where('namespace.nspname', DB::raw('current_schema()'))
            ->whereIn('relation.relname', [
                'custody_journal_source_links',
                'custody_phase2a_cutovers',
            ])
            ->pluck('constraint.conname')
            ->all();

        foreach ([
            'custody_journal_source_links_asset_scale_check',
            'custody_journal_source_links_source_kind_check',
            'custody_journal_source_links_source_version_check',
            'custody_journal_source_links_snapshot_object_check',
            'custody_journal_source_links_snapshot_canonical_check',
            'custody_journal_source_links_snapshot_hash_format_check',
            'custody_journal_source_links_snapshot_hash_check',
            'custody_journal_source_links_snapshot_jsonb_check',
            'custody_phase2a_cutovers_phase_key_check',
            'custody_phase2a_cutovers_baseline_canonical_check',
            'custody_phase2a_cutovers_config_canonical_check',
        ] as $constraint) {
            self::assertContains($constraint, $constraints);
        }

        self::assertSame(2, DB::table('pg_constraint as constraint')
            ->join('pg_class as relation', 'relation.oid', '=', 'constraint.conrelid')
            ->where('relation.relname', 'custody_journal_source_links')
            ->where('constraint.contype', 'f')
            ->where('constraint.confdeltype', 'r')
            ->count());
        self::assertSame(2, DB::table('pg_constraint as constraint')
            ->join('pg_class as relation', 'relation.oid', '=', 'constraint.conrelid')
            ->where('relation.relname', 'custody_journal_source_links')
            ->where('constraint.contype', 'u')
            ->count());

        $triggers = DB::table('pg_trigger as trigger')
            ->join('pg_class as relation', 'relation.oid', '=', 'trigger.tgrelid')
            ->whereIn('trigger.tgname', [
                'custody_phase2a_entry_valid_at_commit',
                'custody_phase2a_posting_valid_at_commit',
                'custody_phase2a_link_valid_at_commit',
                'custody_phase2a_source_valid_at_commit',
                'custody_phase2a_cutover_valid_at_commit',
            ])
            ->get(['trigger.tgname', 'trigger.tgdeferrable', 'trigger.tginitdeferred']);
        self::assertCount(5, $triggers);
        foreach ($triggers as $trigger) {
            self::assertTrue((bool) $trigger->tgdeferrable, $trigger->tgname);
            self::assertTrue((bool) $trigger->tginitdeferred, $trigger->tgname);
        }
    }

    public function test_source_link_checks_reject_invalid_scale_kind_version_text_hash_and_jsonb(): void
    {
        [$merchant, $invoice] = $this->merchantAndInvoice();
        $source = MerchantSettlementEntry::query()->create($this->sourcePayload(
            $merchant,
            $invoice,
            "invoice:{$invoice->id}:internal-credit",
        ));
        $accounts = app(CustodyAccountRepository::class);
        $debit = $accounts->platform('btc', 'bitcoin', CustodyAccount::CODE_TREASURY_AVAILABLE);
        $credit = $accounts->platform('btc', 'bitcoin', CustodyAccount::CODE_FEE_REVENUE);
        $journal = app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
            idempotencyKey: 'custody:test:source-link-check-shell',
            eventType: 'source_link_check_shell',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            postings: [
                new CustodyPostingData($debit->id, 'debit', '0.01000000', '1000000'),
                new CustodyPostingData($credit->id, 'credit', '0.01000000', '1000000'),
            ],
        ));
        $canonical = '{}';
        $base = [
            'merchant_settlement_entry_id' => $source->id,
            'custody_journal_entry_id' => $journal->id,
            'asset_scale' => 8,
            'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
            'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
            'source_snapshot_canonical_text' => $canonical,
            'source_snapshot_hash' => hash('sha256', $canonical),
            'source_snapshot_jsonb' => $canonical,
            'created_at' => now('UTC'),
        ];
        $cases = [
            'custody_journal_source_links_asset_scale_check' => ['asset_scale' => 19],
            'custody_journal_source_links_source_kind_check' => ['source_kind' => 'wrong_kind'],
            'custody_journal_source_links_source_version_check' => ['source_version' => 2],
            'custody_journal_source_links_snapshot_object_check' => [
                'source_snapshot_canonical_text' => '[]',
                'source_snapshot_hash' => hash('sha256', '[]'),
                'source_snapshot_jsonb' => '[]',
            ],
            'custody_journal_source_links_snapshot_canonical_check' => [
                'source_snapshot_canonical_text' => '{"z":1,"a":2}',
                'source_snapshot_hash' => hash('sha256', '{"z":1,"a":2}'),
                'source_snapshot_jsonb' => '{"z":1,"a":2}',
            ],
            'invalid_hash_format' => [
                'source_snapshot_hash' => str_repeat('G', 64),
            ],
            'custody_journal_source_links_snapshot_hash_check' => [
                'source_snapshot_hash' => str_repeat('0', 64),
            ],
            'custody_journal_source_links_snapshot_jsonb_check' => [
                'source_snapshot_jsonb' => '{"different":true}',
            ],
        ];

        foreach ($cases as $constraint => $override) {
            $exception = $this->assertImmediateRejected(
                fn () => DB::table('custody_journal_source_links')->insert(array_merge($base, $override)),
            );
            self::assertStringContainsString(
                $constraint === 'invalid_hash_format'
                    ? 'custody_journal_source_links_snapshot_hash_'
                    : $constraint,
                $exception->getMessage(),
            );
        }
    }

    public function test_post_cutover_completed_source_without_link_is_rejected_at_commit(): void
    {
        $this->activateAndValidate();
        [$merchant, $invoice] = $this->merchantAndInvoice();

        $exception = $this->assertDeferredRejected(function () use ($merchant, $invoice): void {
            MerchantSettlementEntry::query()->create($this->sourcePayload(
                $merchant,
                $invoice,
                "invoice:{$invoice->id}:internal-credit",
            ));
        });

        self::assertStringContainsString('requires exactly one link', $exception->getMessage());
        self::assertSame(0, MerchantSettlementEntry::query()->count());
    }

    public function test_source_journal_postings_and_link_built_in_one_transaction_validate_at_commit(): void
    {
        $this->activateAndValidate();
        $invoice = $this->validInternalCreditInvoice();

        app(InvoiceForwarder::class)->forward($invoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        self::assertSame(1, MerchantSettlementEntry::query()->count());
        self::assertSame(1, CustodyJournalEntry::query()->count());
        self::assertSame(2, DB::table('custody_journal_postings')->count());
        self::assertSame(1, CustodyJournalSourceLink::query()->count());
    }

    public function test_null_inspection_jsonb_accepts_deferred_validation_exact_replay_and_verification(): void
    {
        $this->activateAndValidate();
        DB::beginTransaction();

        try {
            [$merchant, $invoice] = $this->merchantAndInvoice();
            $source = MerchantSettlementEntry::query()->create($this->sourcePayload(
                $merchant,
                $invoice,
                "invoice:{$invoice->id}:internal-credit",
            ));
            $snapshot = app(Phase2ASourceSnapshot::class)->build($source);
            $accounts = app(CustodyAccountRepository::class);
            $offset = $accounts->platform(
                'btc',
                'bitcoin',
                CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET,
            );
            $available = $accounts->merchant(
                $merchant->id,
                'btc',
                'bitcoin',
                CustodyAccount::CODE_MERCHANT_AVAILABLE,
            );
            $journal = app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                idempotencyKey: "custody:internal-credit:merchant-settlement-entry:{$source->id}:v1",
                eventType: 'internal_credit_shadow_v1',
                assetKey: 'btc',
                networkKey: 'bitcoin',
                merchantId: $merchant->id,
                sourceReference: "merchant_settlement_entry:{$source->id}",
                effectiveAt: $source->occurred_at,
                reason: 'internal_balance_only',
                immutableMetadata: [
                    'asset_scale' => $snapshot->assetScale,
                    'merchant_settlement_entry_id' => $source->id,
                    'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
                    'source_snapshot_hash' => $snapshot->hash,
                    'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
                ],
                postings: [
                    new CustodyPostingData($offset->id, 'debit', $snapshot->amount, $snapshot->amountAtomic),
                    new CustodyPostingData($available->id, 'credit', $snapshot->amount, $snapshot->amountAtomic),
                ],
            ));
            DB::table('custody_journal_source_links')->insert([
                'merchant_settlement_entry_id' => $source->id,
                'custody_journal_entry_id' => $journal->id,
                'asset_scale' => $snapshot->assetScale,
                'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
                'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
                'source_snapshot_canonical_text' => $snapshot->canonicalText,
                'source_snapshot_hash' => $snapshot->hash,
                'source_snapshot_jsonb' => null,
                'created_at' => now('UTC'),
            ]);
            MerchantBalance::query()->create([
                'merchant_id' => $merchant->id,
                'coin' => 'btc',
                'amount' => $snapshot->amount,
            ]);

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $link = CustodyJournalSourceLink::query()->sole();
            self::assertNull($link->source_snapshot_jsonb);
            self::assertSame(
                $journal->id,
                app(Phase2AInternalCreditProjector::class)->replayExact($source->id)->id,
            );
            self::assertTrue(app(Phase2AVerifier::class)->verify()['clean']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_wrong_source_key_journal_identity_metadata_time_and_postings_are_rejected(): void
    {
        [$merchant, $invoice] = $this->merchantAndInvoice();
        $source = MerchantSettlementEntry::query()->create($this->sourcePayload(
            $merchant,
            $invoice,
            "invoice:{$invoice->id}:internal-credit",
        ));
        $snapshot = app(Phase2ASourceSnapshot::class)->build($source);
        $accounts = app(CustodyAccountRepository::class);
        $offset = $accounts->platform(
            'btc',
            'bitcoin',
            CustodyAccount::CODE_INTERNAL_CREDIT_SHADOW_OFFSET,
        );
        $available = $accounts->merchant(
            $merchant->id,
            'btc',
            'bitcoin',
            CustodyAccount::CODE_MERCHANT_AVAILABLE,
        );
        $metadata = [
            'asset_scale' => 8,
            'merchant_settlement_entry_id' => $source->id,
            'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
            'source_snapshot_hash' => $snapshot->hash,
            'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
        ];
        $cases = [
            'stable-key' => [
                'idempotencyKey' => "custody:wrong:{$source->id}",
                'expected' => 'journal identity does not match source',
            ],
            'source-reference' => [
                'sourceReference' => "merchant_settlement_entry:wrong:{$source->id}",
                'expected' => 'journal identity does not match source',
            ],
            'effective-time' => [
                'effectiveAt' => $source->occurred_at->copy()->addSecond(),
                'expected' => 'journal identity does not match source',
            ],
            'metadata-source-id' => [
                'immutableMetadata' => [...$metadata, 'merchant_settlement_entry_id' => $source->id + 1],
                'expected' => 'journal identity does not match source',
            ],
            'posting-amount' => [
                'amount' => '0.48000000',
                'atomic' => '48000000',
                'expected' => 'does not have the exact two-posting shape',
            ],
        ];

        foreach ($cases as $name => $override) {
            $exception = $this->assertDeferredRejected(function () use (
                $source,
                $snapshot,
                $offset,
                $available,
                $metadata,
                $override,
            ): void {
                $amount = $override['amount'] ?? $snapshot->amount;
                $atomic = $override['atomic'] ?? $snapshot->amountAtomic;
                $journal = app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                    idempotencyKey: $override['idempotencyKey']
                        ?? "custody:internal-credit:merchant-settlement-entry:{$source->id}:v1",
                    eventType: 'internal_credit_shadow_v1',
                    assetKey: 'btc',
                    networkKey: 'bitcoin',
                    merchantId: $source->merchant_id,
                    sourceReference: $override['sourceReference']
                        ?? "merchant_settlement_entry:{$source->id}",
                    effectiveAt: $override['effectiveAt'] ?? $source->occurred_at,
                    reason: 'internal_balance_only',
                    immutableMetadata: $override['immutableMetadata'] ?? $metadata,
                    postings: [
                        new CustodyPostingData($offset->id, 'debit', $amount, $atomic),
                        new CustodyPostingData($available->id, 'credit', $amount, $atomic),
                    ],
                ));
                DB::table('custody_journal_source_links')->insert([
                    'merchant_settlement_entry_id' => $source->id,
                    'custody_journal_entry_id' => $journal->id,
                    'asset_scale' => $snapshot->assetScale,
                    'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
                    'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
                    'source_snapshot_canonical_text' => $snapshot->canonicalText,
                    'source_snapshot_hash' => $snapshot->hash,
                    'source_snapshot_jsonb' => $snapshot->canonicalText,
                    'created_at' => now('UTC'),
                ]);
            });
            self::assertStringContainsString($override['expected'], $exception->getMessage(), $name);
        }

        [$otherMerchant, $otherInvoice] = $this->merchantAndInvoice();
        $wrongKeySource = MerchantSettlementEntry::query()->create($this->sourcePayload(
            $otherMerchant,
            $otherInvoice,
            "invoice:{$otherInvoice->id}:backfill:internal-credit",
        ));
        try {
            app(Phase2ASourceSnapshot::class)->build($wrongKeySource);
            self::fail('The live Phase 2A snapshot must reject a backfill stable key.');
        } catch (CustodyAccountingException $e) {
            self::assertStringContainsString('key or metadata is not exact', $e->getMessage());
        }
    }

    public function test_snapshot_validation_reads_raw_stored_asset_and_fee_precision(): void
    {
        foreach ([
            'amount_coin' => '0.490000001000000000',
            'fee_coin' => '0.010000001000000000',
        ] as $field => $invalidValue) {
            [$merchant, $invoice] = $this->merchantAndInvoice();
            $payload = $this->sourcePayload(
                $merchant,
                $invoice,
                "invoice:{$invoice->id}:internal-credit",
            );
            $payload[$field] = $invalidValue;
            $sourceId = MerchantSettlementEntry::query()->create($payload)->id;
            $source = MerchantSettlementEntry::query()->findOrFail($sourceId);

            try {
                app(Phase2ASourceSnapshot::class)->build($source);
                self::fail("Raw {$field} excess precision must be rejected.");
            } catch (CustodyAccountingException $e) {
                self::assertStringContainsString('non-zero precision', $e->getMessage(), $field);
            }
        }
    }

    public function test_source_link_cutover_and_accounts_are_immediately_immutable(): void
    {
        $this->activateAndValidate();
        $invoice = $this->validInternalCreditInvoice();
        app(InvoiceForwarder::class)->forward($invoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $source = MerchantSettlementEntry::query()->sole();
        $link = CustodyJournalSourceLink::query()->sole();
        $account = CustodyAccount::query()->orderBy('id')->firstOrFail();

        $cases = [
            ['source links are append-only', fn () => DB::table('custody_journal_source_links')
                ->where('id', $link->id)->update(['source_version' => 2])],
            ['source links are append-only', fn () => DB::table('custody_journal_source_links')
                ->where('id', $link->id)->delete()],
            ['source financial evidence is immutable', fn () => DB::table('merchant_settlement_entries')
                ->where('id', $source->id)->update(['amount_coin' => '0.48000000'])],
            ['cutover is immutable and one-way', fn () => DB::table('custody_phase2a_cutovers')
                ->where('phase_key', CustodyPhase2ACutover::PHASE_KEY)
                ->update(['activation_reference' => 'mutated'])],
            ['cutover is immutable and one-way', fn () => DB::table('custody_phase2a_cutovers')
                ->where('phase_key', CustodyPhase2ACutover::PHASE_KEY)
                ->delete()],
            ['custody accounts are immutable', fn () => DB::table('custody_accounts')
                ->where('id', $account->id)->update(['asset_scale' => 7])],
        ];

        foreach ($cases as [$message, $callback]) {
            $exception = $this->assertImmediateRejected($callback);
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_completed_internal_credit_invoice_unique_index_rejects_live_and_backfill_keys(): void
    {
        [$merchant, $invoice] = $this->merchantAndInvoice();
        MerchantSettlementEntry::query()->create($this->sourcePayload(
            $merchant,
            $invoice,
            "invoice:{$invoice->id}:internal-credit",
        ));

        $exception = $this->assertImmediateRejected(function () use ($merchant, $invoice): void {
            MerchantSettlementEntry::query()->create($this->sourcePayload(
                $merchant,
                $invoice,
                "invoice:{$invoice->id}:backfill:internal-credit",
            ));
        });

        self::assertStringContainsString(
            'merchant_settlement_entries_completed_internal_credit_invoice_u',
            $exception->getMessage(),
        );
        self::assertSame(1, MerchantSettlementEntry::query()->count());
    }

    public function test_only_phase2a_event_can_use_shadow_offset_and_phase2a_event_cannot_be_reversed(): void
    {
        $this->activateAndValidate();
        $invoice = $this->validInternalCreditInvoice();
        app(InvoiceForwarder::class)->forward($invoice->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $journal = CustodyJournalEntry::query()->with('postings')->sole();
        $debit = $journal->postings->firstWhere('side', CustodyAccount::SIDE_DEBIT);
        $credit = $journal->postings->firstWhere('side', CustodyAccount::SIDE_CREDIT);

        $offsetException = $this->assertDeferredRejected(function () use ($invoice, $debit, $credit): void {
            app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                idempotencyKey: 'custody:test:wrong-offset-event',
                eventType: 'wrong_event',
                assetKey: 'btc',
                networkKey: 'bitcoin',
                merchantId: $invoice->merchant_id,
                postings: [
                    new CustodyPostingData($debit->account_id, 'debit', '0.01000000', '1000000'),
                    new CustodyPostingData($credit->account_id, 'credit', '0.01000000', '1000000'),
                ],
            ));
        });
        self::assertStringContainsString(
            'only internal_credit_shadow_v1 may post to the shadow offset account',
            $offsetException->getMessage(),
        );

        $reversalException = $this->assertDeferredRejected(function () use ($invoice, $journal, $debit, $credit): void {
            app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                idempotencyKey: 'custody:test:phase2a-reversal',
                eventType: 'correction',
                assetKey: 'btc',
                networkKey: 'bitcoin',
                merchantId: $invoice->merchant_id,
                reversalOfId: $journal->id,
                postings: [
                    new CustodyPostingData($debit->account_id, 'credit', '0.49000000', '49000000'),
                    new CustodyPostingData($credit->account_id, 'debit', '0.49000000', '49000000'),
                ],
            ));
        });
        self::assertStringContainsString('shadow journals cannot be reversed', $reversalException->getMessage());
    }

    private function activateAndValidate(): void
    {
        app(Phase2ACutoverActivator::class)->activate('phpunit-integrity-v1');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function validInternalCreditInvoice(): Invoice
    {
        [$merchant, $invoice] = $this->merchantAndInvoice();
        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);

        return $invoice;
    }

    /**
     * @return array{Merchant, Invoice}
     */
    private function merchantAndInvoice(): array
    {
        $merchant = $this->createMerchant(['fee_percent' => '2.00']);
        $invoice = $this->createInvoice($merchant, [
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

        return [$merchant, $invoice];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePayload(Merchant $merchant, Invoice $invoice, string $key): array
    {
        return [
            'merchant_id' => $merchant->id,
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
            'idempotency_key' => $key,
            'error_message' => null,
            'metadata' => [
                'invoice_public_id' => $invoice->public_id,
                'reason' => 'internal_balance_only',
            ],
            'occurred_at' => now('UTC'),
        ];
    }

    private function assertDeferredRejected(callable $callback): QueryException
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('Expected deferred PostgreSQL constraint rejection.');
        } catch (QueryException $e) {
            return $e;
        } finally {
            DB::rollBack();
        }
    }

    private function assertImmediateRejected(callable $callback): QueryException
    {
        DB::beginTransaction();

        try {
            $callback();
            self::fail('Expected immediate PostgreSQL rejection.');
        } catch (QueryException $e) {
            return $e;
        } finally {
            DB::rollBack();
        }
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
