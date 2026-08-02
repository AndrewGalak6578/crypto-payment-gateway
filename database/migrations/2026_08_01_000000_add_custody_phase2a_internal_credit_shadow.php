<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertPostgreSqlCapabilities();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION custody_phase2a_canonical_json_text(input_value jsonb)
            RETURNS text
            LANGUAGE plpgsql
            IMMUTABLE
            STRICT
            PARALLEL SAFE
            AS $$
            DECLARE
                value_type text;
                canonical text;
            BEGIN
                value_type := jsonb_typeof(input_value);

                IF value_type = 'object' THEN
                    SELECT '{' || COALESCE(
                        string_agg(
                            to_json(object_item.key)::text || ':'
                                || custody_phase2a_canonical_json_text(object_item.value),
                            ',' ORDER BY convert_to(object_item.key, 'UTF8')
                        ),
                        ''
                    ) || '}'
                    INTO canonical
                    FROM jsonb_each(input_value) AS object_item;

                    RETURN canonical;
                END IF;

                IF value_type = 'array' THEN
                    SELECT '[' || COALESCE(
                        string_agg(
                            custody_phase2a_canonical_json_text(array_item.value),
                            ',' ORDER BY array_item.ordinality
                        ),
                        ''
                    ) || ']'
                    INTO canonical
                    FROM jsonb_array_elements(input_value) WITH ORDINALITY AS array_item(value, ordinality);

                    RETURN canonical;
                END IF;

                RETURN input_value::text;
            END;
            $$;
            SQL);

        DB::statement('ALTER TABLE custody_accounts DROP CONSTRAINT custody_accounts_ownership_check');
        DB::statement(<<<'SQL'
            ALTER TABLE custody_accounts
            ADD CONSTRAINT custody_accounts_ownership_check
            CHECK (
                (
                    account_code IN ('merchant_available', 'merchant_reserved', 'merchant_held')
                    AND merchant_id IS NOT NULL
                    AND scope_key = 'merchant:' || merchant_id::text
                    AND normal_side = 'credit'
                )
                OR
                (
                    account_code IN (
                        'deposit_uncollected',
                        'treasury_available',
                        'treasury_reserved',
                        'outbound',
                        'fee_revenue',
                        'network_fee_expense',
                        'internal_credit_shadow_offset'
                    )
                    AND merchant_id IS NULL
                    AND scope_key = 'platform'
                    AND normal_side = CASE
                        WHEN account_code = 'fee_revenue' THEN 'credit'
                        ELSE 'debit'
                    END
                )
                OR
                (
                    account_code = 'migration_suspense'
                    AND merchant_id IS NULL
                    AND scope_key = 'migration'
                    AND normal_side = 'debit'
                )
            )
            SQL);

        $duplicates = DB::table('merchant_settlement_entries')
            ->select('invoice_id')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->whereNotNull('invoice_id')
            ->where('type', 'internal_credit')
            ->where('status', 'completed')
            ->groupBy('invoice_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('invoice_id')
            ->pluck('duplicate_count', 'invoice_id');

        if ($duplicates->isNotEmpty()) {
            $invoiceIds = $duplicates->keys()->map(static fn (mixed $id): int => (int) $id)->implode(', ');

            throw new RuntimeException(
                "Phase 2A cannot create completed internal-credit uniqueness; duplicate invoice IDs: {$invoiceIds}."
            );
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX merchant_settlement_entries_completed_internal_credit_invoice_unique
            ON merchant_settlement_entries (invoice_id)
            WHERE invoice_id IS NOT NULL
              AND type = 'internal_credit'
              AND status = 'completed'
            SQL);

        Schema::create('custody_journal_source_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_settlement_entry_id')
                ->unique()
                ->constrained('merchant_settlement_entries')
                ->restrictOnDelete();
            $table->foreignId('custody_journal_entry_id')
                ->unique()
                ->constrained('custody_journal_entries')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('asset_scale');
            $table->string('source_kind', 64);
            $table->unsignedSmallInteger('source_version');
            $table->text('source_snapshot_canonical_text');
            $table->char('source_snapshot_hash', 64);
            $table->jsonb('source_snapshot_jsonb')->nullable();
            $table->timestampTz('created_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE custody_journal_source_links
                ADD CONSTRAINT custody_journal_source_links_asset_scale_check
                    CHECK (asset_scale BETWEEN 0 AND 18),
                ADD CONSTRAINT custody_journal_source_links_source_kind_check
                    CHECK (source_kind = 'live_explicit_internal_credit'),
                ADD CONSTRAINT custody_journal_source_links_source_version_check
                    CHECK (source_version = 1),
                ADD CONSTRAINT custody_journal_source_links_snapshot_object_check
                    CHECK (jsonb_typeof(source_snapshot_canonical_text::jsonb) = 'object'),
                ADD CONSTRAINT custody_journal_source_links_snapshot_canonical_check
                    CHECK (
                        source_snapshot_canonical_text
                            = custody_phase2a_canonical_json_text(source_snapshot_canonical_text::jsonb)
                    ),
                ADD CONSTRAINT custody_journal_source_links_snapshot_hash_format_check
                    CHECK (source_snapshot_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT custody_journal_source_links_snapshot_hash_check
                    CHECK (
                        source_snapshot_hash
                            = encode(sha256(convert_to(source_snapshot_canonical_text, 'UTF8')), 'hex')
                    ),
                ADD CONSTRAINT custody_journal_source_links_snapshot_jsonb_check
                    CHECK (
                        source_snapshot_jsonb IS NULL
                        OR source_snapshot_jsonb = source_snapshot_canonical_text::jsonb
                    );
            SQL);

        Schema::create('custody_phase2a_cutovers', function (Blueprint $table): void {
            $table->string('phase_key', 64)->primary();
            $table->timestampTz('activated_at');
            $table->string('activation_reference', 191);
            $table->text('baseline_verification_canonical_text');
            $table->char('baseline_verification_hash', 64);
            $table->text('activation_config_canonical_text');
            $table->char('activation_config_fingerprint', 64);
            $table->timestampTz('created_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE custody_phase2a_cutovers
                ADD CONSTRAINT custody_phase2a_cutovers_phase_key_check
                    CHECK (phase_key = 'internal_credit_shadow_v1'),
                ADD CONSTRAINT custody_phase2a_cutovers_activation_reference_check
                    CHECK (length(activation_reference) BETWEEN 1 AND 191),
                ADD CONSTRAINT custody_phase2a_cutovers_baseline_object_check
                    CHECK (jsonb_typeof(baseline_verification_canonical_text::jsonb) = 'object'),
                ADD CONSTRAINT custody_phase2a_cutovers_baseline_canonical_check
                    CHECK (
                        baseline_verification_canonical_text
                            = custody_phase2a_canonical_json_text(baseline_verification_canonical_text::jsonb)
                    ),
                ADD CONSTRAINT custody_phase2a_cutovers_baseline_hash_format_check
                    CHECK (baseline_verification_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT custody_phase2a_cutovers_baseline_hash_check
                    CHECK (
                        baseline_verification_hash
                            = encode(
                                sha256(convert_to(baseline_verification_canonical_text, 'UTF8')),
                                'hex'
                            )
                    ),
                ADD CONSTRAINT custody_phase2a_cutovers_config_object_check
                    CHECK (jsonb_typeof(activation_config_canonical_text::jsonb) = 'object'),
                ADD CONSTRAINT custody_phase2a_cutovers_config_canonical_check
                    CHECK (
                        activation_config_canonical_text
                            = custody_phase2a_canonical_json_text(activation_config_canonical_text::jsonb)
                    ),
                ADD CONSTRAINT custody_phase2a_cutovers_config_hash_format_check
                    CHECK (activation_config_fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT custody_phase2a_cutovers_config_hash_check
                    CHECK (
                        activation_config_fingerprint
                            = encode(
                                sha256(convert_to(activation_config_canonical_text, 'UTF8')),
                                'hex'
                            )
                    );
            SQL);

        $this->installImmediateImmutabilityTriggers();
        $this->installDeferredInvariantTriggers();
    }

    public function down(): void
    {
        $this->assertPostgreSqlCapabilities();

        if (
            Schema::hasTable('custody_phase2a_cutovers')
            && DB::table('custody_phase2a_cutovers')->exists()
        ) {
            throw new RuntimeException(
                'Phase 2A cutover is immutable; rollback is prohibited after activation.'
            );
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS custody_phase2a_cutover_valid_at_commit ON custody_phase2a_cutovers;
            DROP TRIGGER IF EXISTS custody_phase2a_source_valid_at_commit ON merchant_settlement_entries;
            DROP TRIGGER IF EXISTS custody_phase2a_link_valid_at_commit ON custody_journal_source_links;
            DROP TRIGGER IF EXISTS custody_phase2a_posting_valid_at_commit ON custody_journal_postings;
            DROP TRIGGER IF EXISTS custody_phase2a_entry_valid_at_commit ON custody_journal_entries;
            DROP FUNCTION IF EXISTS custody_phase2a_validate_cutover_trigger();
            DROP FUNCTION IF EXISTS custody_phase2a_validate_source_trigger();
            DROP FUNCTION IF EXISTS custody_phase2a_validate_link_trigger();
            DROP FUNCTION IF EXISTS custody_phase2a_validate_entry_trigger();
            DROP FUNCTION IF EXISTS custody_phase2a_validate_cutover(text);
            DROP FUNCTION IF EXISTS custody_phase2a_validate_source(bigint);
            DROP FUNCTION IF EXISTS custody_phase2a_validate_entry(bigint);
            DROP FUNCTION IF EXISTS custody_phase2a_validate_link(bigint);
            DROP FUNCTION IF EXISTS custody_phase2a_atomic_text(numeric, smallint);
            DROP FUNCTION IF EXISTS custody_phase2a_decimal_text(numeric, smallint);

            DROP TRIGGER IF EXISTS custody_phase2a_source_linked_immutable
                ON merchant_settlement_entries;
            DROP TRIGGER IF EXISTS custody_phase2a_cutover_immutable ON custody_phase2a_cutovers;
            DROP TRIGGER IF EXISTS custody_phase2a_source_link_immutable
                ON custody_journal_source_links;
            DROP FUNCTION IF EXISTS custody_phase2a_guard_linked_source_mutation();
            DROP FUNCTION IF EXISTS custody_phase2a_reject_cutover_mutation();
            DROP FUNCTION IF EXISTS custody_phase2a_reject_source_link_mutation();
            SQL);

        Schema::dropIfExists('custody_phase2a_cutovers');
        Schema::dropIfExists('custody_journal_source_links');

        DB::statement('DROP INDEX IF EXISTS merchant_settlement_entries_completed_internal_credit_invoice_unique');
        DB::statement('ALTER TABLE custody_accounts DROP CONSTRAINT custody_accounts_ownership_check');
        DB::statement(<<<'SQL'
            ALTER TABLE custody_accounts
            ADD CONSTRAINT custody_accounts_ownership_check
            CHECK (
                (
                    account_code IN ('merchant_available', 'merchant_reserved', 'merchant_held')
                    AND merchant_id IS NOT NULL
                    AND scope_key = 'merchant:' || merchant_id::text
                    AND normal_side = 'credit'
                )
                OR
                (
                    account_code IN (
                        'deposit_uncollected',
                        'treasury_available',
                        'treasury_reserved',
                        'outbound',
                        'fee_revenue',
                        'network_fee_expense'
                    )
                    AND merchant_id IS NULL
                    AND scope_key = 'platform'
                    AND normal_side = CASE
                        WHEN account_code = 'fee_revenue' THEN 'credit'
                        ELSE 'debit'
                    END
                )
                OR
                (
                    account_code = 'migration_suspense'
                    AND merchant_id IS NULL
                    AND scope_key = 'migration'
                    AND normal_side = 'debit'
                )
            )
            SQL);

        DB::statement('DROP FUNCTION IF EXISTS custody_phase2a_canonical_json_text(jsonb)');
    }

    private function assertPostgreSqlCapabilities(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Custody accounting Phase 2A requires PostgreSQL.');
        }

        $capability = DB::selectOne("SELECT to_regprocedure('sha256(bytea)')::text AS procedure_name");
        if (($capability->procedure_name ?? null) !== 'sha256(bytea)') {
            throw new RuntimeException(
                'Custody accounting Phase 2A requires the built-in PostgreSQL sha256(bytea) function.'
            );
        }
    }

    private function installImmediateImmutabilityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION custody_phase2a_reject_source_link_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'custody Phase 2A source links are append-only';
            END;
            $$;

            CREATE TRIGGER custody_phase2a_source_link_immutable
            BEFORE UPDATE OR DELETE ON custody_journal_source_links
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_reject_source_link_mutation();

            CREATE OR REPLACE FUNCTION custody_phase2a_reject_cutover_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'custody Phase 2A cutover is immutable and one-way';
            END;
            $$;

            CREATE TRIGGER custody_phase2a_cutover_immutable
            BEFORE UPDATE OR DELETE ON custody_phase2a_cutovers
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_reject_cutover_mutation();

            CREATE OR REPLACE FUNCTION custody_phase2a_guard_linked_source_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                source_id bigint;
            BEGIN
                source_id := OLD.id;

                IF NOT EXISTS (
                    SELECT 1
                    FROM custody_journal_source_links
                    WHERE merchant_settlement_entry_id = source_id
                ) THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'linked completed internal-credit sources are immutable';
                END IF;

                IF (
                    NEW.merchant_id,
                    NEW.invoice_id,
                    NEW.settlement_attempt_id,
                    NEW.asset_key,
                    NEW.network_key,
                    NEW.type,
                    NEW.status,
                    NEW.amount_coin,
                    NEW.fee_coin,
                    NEW.amount_usd,
                    NEW.destination_wallet,
                    NEW.txid,
                    NEW.idempotency_key,
                    NEW.error_message,
                    NEW.metadata::jsonb,
                    NEW.occurred_at
                ) IS DISTINCT FROM (
                    OLD.merchant_id,
                    OLD.invoice_id,
                    OLD.settlement_attempt_id,
                    OLD.asset_key,
                    OLD.network_key,
                    OLD.type,
                    OLD.status,
                    OLD.amount_coin,
                    OLD.fee_coin,
                    OLD.amount_usd,
                    OLD.destination_wallet,
                    OLD.txid,
                    OLD.idempotency_key,
                    OLD.error_message,
                    OLD.metadata::jsonb,
                    OLD.occurred_at
                ) THEN
                    RAISE EXCEPTION 'linked completed internal-credit source financial evidence is immutable';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER custody_phase2a_source_linked_immutable
            BEFORE UPDATE OR DELETE ON merchant_settlement_entries
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_guard_linked_source_mutation();
            SQL);
    }

    private function installDeferredInvariantTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION custody_phase2a_decimal_text(input_value numeric, input_scale smallint)
            RETURNS text
            LANGUAGE plpgsql
            IMMUTABLE
            STRICT
            PARALLEL SAFE
            AS $$
            DECLARE
                rendered text;
                fraction_length integer;
            BEGIN
                IF input_scale < 0 OR input_scale > 18 THEN
                    RAISE EXCEPTION 'custody Phase 2A decimal scale is invalid';
                END IF;

                IF input_value <> trunc(input_value, input_scale) THEN
                    RAISE EXCEPTION 'custody Phase 2A decimal has non-zero excess precision';
                END IF;

                rendered := trunc(input_value, input_scale)::text;

                IF input_scale = 0 THEN
                    RETURN split_part(rendered, '.', 1);
                END IF;

                IF position('.' IN rendered) = 0 THEN
                    RETURN rendered || '.' || repeat('0', input_scale);
                END IF;

                fraction_length := length(split_part(rendered, '.', 2));

                RETURN rendered || repeat('0', input_scale - fraction_length);
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_atomic_text(input_value numeric, input_scale smallint)
            RETURNS text
            LANGUAGE plpgsql
            IMMUTABLE
            STRICT
            PARALLEL SAFE
            AS $$
            DECLARE
                atomic text;
            BEGIN
                atomic := ltrim(
                    replace(custody_phase2a_decimal_text(input_value, input_scale), '.', ''),
                    '0'
                );

                RETURN CASE WHEN atomic = '' THEN '0' ELSE atomic END;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_link(target_link_id bigint)
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_link custody_journal_source_links%ROWTYPE;
                source_row merchant_settlement_entries%ROWTYPE;
                journal_row custody_journal_entries%ROWTYPE;
                invoice_row invoices%ROWTYPE;
                snapshot jsonb;
                expected_metadata jsonb;
                expected_journal_metadata jsonb;
                expected_occurred_at text;
                posting_count bigint;
                distinct_account_count bigint;
                matching_debit_count bigint;
                matching_credit_count bigint;
                invalid_posting_count bigint;
                snapshot_keys text[];
                metadata_keys text[];
            BEGIN
                SELECT * INTO target_link
                FROM custody_journal_source_links
                WHERE id = target_link_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT * INTO source_row
                FROM merchant_settlement_entries
                WHERE id = target_link.merchant_settlement_entry_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'custody Phase 2A source link % has no settlement source', target_link_id;
                END IF;

                SELECT * INTO journal_row
                FROM custody_journal_entries
                WHERE id = target_link.custody_journal_entry_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'custody Phase 2A source link % has no journal entry', target_link_id;
                END IF;

                SELECT * INTO invoice_row
                FROM invoices
                WHERE id = source_row.invoice_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'custody Phase 2A source % has no invoice', source_row.id;
                END IF;

                IF source_row.type <> 'internal_credit'
                    OR source_row.status <> 'completed'
                    OR source_row.invoice_id IS NULL
                    OR source_row.network_key IS NULL
                    OR source_row.occurred_at IS NULL
                    OR source_row.settlement_attempt_id IS NOT NULL
                    OR source_row.destination_wallet IS NOT NULL
                    OR source_row.txid IS NOT NULL
                    OR source_row.error_message IS NOT NULL
                    OR source_row.amount_coin <= 0
                THEN
                    RAISE EXCEPTION 'custody Phase 2A source % does not have the exact live internal-credit shape',
                        source_row.id;
                END IF;

                expected_metadata := jsonb_build_object(
                    'invoice_public_id', invoice_row.public_id,
                    'reason', 'internal_balance_only'
                );

                IF source_row.merchant_id <> invoice_row.merchant_id
                    OR source_row.idempotency_key <> 'invoice:' || source_row.invoice_id::text || ':internal-credit'
                    OR source_row.metadata::jsonb IS DISTINCT FROM expected_metadata
                THEN
                    RAISE EXCEPTION 'custody Phase 2A source % identity or metadata is invalid', source_row.id;
                END IF;

                snapshot := target_link.source_snapshot_canonical_text::jsonb;
                SELECT array_agg(key ORDER BY convert_to(key, 'UTF8')) INTO snapshot_keys
                FROM jsonb_object_keys(snapshot) AS keys(key);

                IF snapshot_keys IS DISTINCT FROM ARRAY[
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
                    'type'
                ]::text[] THEN
                    RAISE EXCEPTION 'custody Phase 2A source snapshot has an invalid top-level key set';
                END IF;

                SELECT array_agg(key ORDER BY convert_to(key, 'UTF8')) INTO metadata_keys
                FROM jsonb_object_keys(snapshot->'metadata') AS keys(key);

                IF jsonb_typeof(snapshot->'metadata') <> 'object'
                    OR metadata_keys IS DISTINCT FROM ARRAY['invoice_public_id', 'reason']::text[]
                    OR snapshot->'metadata' IS DISTINCT FROM expected_metadata
                THEN
                    RAISE EXCEPTION 'custody Phase 2A source snapshot metadata is invalid';
                END IF;

                expected_occurred_at := to_char(
                    source_row.occurred_at AT TIME ZONE 'UTC',
                    'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'
                );

                IF jsonb_typeof(snapshot->'amount_coin') <> 'string'
                    OR snapshot->>'amount_coin'
                        <> custody_phase2a_decimal_text(
                            source_row.amount_coin,
                            target_link.asset_scale::smallint
                        )
                    OR (
                        source_row.fee_coin IS NULL
                        AND snapshot->'fee_coin' IS DISTINCT FROM 'null'::jsonb
                    )
                    OR (
                        source_row.fee_coin IS NOT NULL
                        AND (
                            jsonb_typeof(snapshot->'fee_coin') <> 'string'
                            OR snapshot->>'fee_coin'
                                <> custody_phase2a_decimal_text(
                                    source_row.fee_coin,
                                    target_link.asset_scale::smallint
                                )
                        )
                    )
                    OR (
                        source_row.amount_usd IS NULL
                        AND snapshot->'amount_usd' IS DISTINCT FROM 'null'::jsonb
                    )
                    OR (
                        source_row.amount_usd IS NOT NULL
                        AND (
                            jsonb_typeof(snapshot->'amount_usd') <> 'string'
                            OR snapshot->>'amount_usd'
                                <> custody_phase2a_decimal_text(source_row.amount_usd, 2::smallint)
                        )
                    )
                    OR snapshot->>'asset_key' <> source_row.asset_key
                    OR jsonb_typeof(snapshot->'asset_scale') <> 'number'
                    OR snapshot->>'asset_scale' <> target_link.asset_scale::text
                    OR snapshot->'destination_wallet' IS DISTINCT FROM 'null'::jsonb
                    OR snapshot->'error_message' IS DISTINCT FROM 'null'::jsonb
                    OR jsonb_typeof(snapshot->'id') <> 'number'
                    OR snapshot->>'id' <> source_row.id::text
                    OR snapshot->>'idempotency_key' <> source_row.idempotency_key
                    OR jsonb_typeof(snapshot->'invoice_id') <> 'number'
                    OR snapshot->>'invoice_id' <> source_row.invoice_id::text
                    OR jsonb_typeof(snapshot->'merchant_id') <> 'number'
                    OR snapshot->>'merchant_id' <> source_row.merchant_id::text
                    OR snapshot->>'network_key' <> source_row.network_key
                    OR snapshot->>'occurred_at' <> expected_occurred_at
                    OR snapshot->'settlement_attempt_id' IS DISTINCT FROM 'null'::jsonb
                    OR snapshot->>'source_kind' <> 'live_explicit_internal_credit'
                    OR jsonb_typeof(snapshot->'source_version') <> 'number'
                    OR snapshot->>'source_version' <> '1'
                    OR snapshot->>'status' <> 'completed'
                    OR snapshot->'txid' IS DISTINCT FROM 'null'::jsonb
                    OR snapshot->>'type' <> 'internal_credit'
                THEN
                    RAISE EXCEPTION 'custody Phase 2A source snapshot does not exactly match source %', source_row.id;
                END IF;

                IF target_link.source_kind <> 'live_explicit_internal_credit'
                    OR target_link.source_version <> 1
                    OR target_link.source_snapshot_hash
                        <> encode(
                            sha256(convert_to(target_link.source_snapshot_canonical_text, 'UTF8')),
                            'hex'
                        )
                    OR (
                        target_link.source_snapshot_jsonb IS NOT NULL
                        AND target_link.source_snapshot_jsonb IS DISTINCT FROM snapshot
                    )
                THEN
                    RAISE EXCEPTION 'custody Phase 2A source link metadata is invalid';
                END IF;

                expected_journal_metadata := jsonb_build_object(
                    'asset_scale', target_link.asset_scale,
                    'merchant_settlement_entry_id', source_row.id,
                    'source_kind', target_link.source_kind,
                    'source_snapshot_hash', target_link.source_snapshot_hash,
                    'source_version', target_link.source_version
                );

                IF journal_row.event_type <> 'internal_credit_shadow_v1'
                    OR journal_row.posted_at IS NULL
                    OR journal_row.reversal_of_id IS NOT NULL
                    OR journal_row.merchant_id IS DISTINCT FROM source_row.merchant_id
                    OR journal_row.idempotency_key
                        <> 'custody:internal-credit:merchant-settlement-entry:' || source_row.id::text || ':v1'
                    OR journal_row.source_reference
                        <> 'merchant_settlement_entry:' || source_row.id::text
                    OR journal_row.effective_at IS DISTINCT FROM source_row.occurred_at
                    OR journal_row.asset_key <> source_row.asset_key
                    OR journal_row.network_key <> source_row.network_key
                    OR journal_row.asset_scale <> target_link.asset_scale
                    OR journal_row.reason <> 'internal_balance_only'
                    OR journal_row.immutable_metadata IS DISTINCT FROM expected_journal_metadata
                    OR EXISTS (
                        SELECT 1
                        FROM custody_journal_entries reversal
                        WHERE reversal.reversal_of_id = journal_row.id
                    )
                THEN
                    RAISE EXCEPTION 'custody Phase 2A journal identity does not match source %', source_row.id;
                END IF;

                SELECT
                    COUNT(*),
                    COUNT(DISTINCT posting.account_id),
                    COUNT(*) FILTER (
                        WHERE posting.side = 'debit'
                          AND account.account_code = 'internal_credit_shadow_offset'
                          AND account.scope_key = 'platform'
                          AND account.merchant_id IS NULL
                          AND account.normal_side = 'debit'
                    ),
                    COUNT(*) FILTER (
                        WHERE posting.side = 'credit'
                          AND account.account_code = 'merchant_available'
                          AND account.scope_key = 'merchant:' || source_row.merchant_id::text
                          AND account.merchant_id = source_row.merchant_id
                          AND account.normal_side = 'credit'
                    ),
                    COUNT(*) FILTER (
                        WHERE posting.amount <> source_row.amount_coin
                           OR posting.amount_atomic IS DISTINCT FROM custody_phase2a_atomic_text(
                                source_row.amount_coin,
                                target_link.asset_scale::smallint
                           )
                           OR account.asset_key <> source_row.asset_key
                           OR account.network_key <> source_row.network_key
                           OR account.asset_scale <> target_link.asset_scale
                    )
                INTO
                    posting_count,
                    distinct_account_count,
                    matching_debit_count,
                    matching_credit_count,
                    invalid_posting_count
                FROM custody_journal_postings posting
                JOIN custody_accounts account ON account.id = posting.account_id
                WHERE posting.journal_entry_id = journal_row.id;

                IF posting_count <> 2
                    OR distinct_account_count <> 2
                    OR matching_debit_count <> 1
                    OR matching_credit_count <> 1
                    OR invalid_posting_count <> 0
                THEN
                    RAISE EXCEPTION 'custody Phase 2A journal % does not have the exact two-posting shape',
                        journal_row.id;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_entry(target_entry_id bigint)
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                journal_row custody_journal_entries%ROWTYPE;
                link_id bigint;
                link_count bigint;
            BEGIN
                SELECT * INTO journal_row
                FROM custody_journal_entries
                WHERE id = target_entry_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                IF journal_row.reversal_of_id IS NOT NULL AND EXISTS (
                    SELECT 1
                    FROM custody_journal_entries target
                    JOIN custody_journal_source_links source_link
                        ON source_link.custody_journal_entry_id = target.id
                    WHERE target.id = journal_row.reversal_of_id
                      AND target.event_type = 'internal_credit_shadow_v1'
                ) THEN
                    RAISE EXCEPTION 'custody Phase 2A shadow journals cannot be reversed';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM custody_journal_postings posting
                    JOIN custody_accounts account ON account.id = posting.account_id
                    WHERE posting.journal_entry_id = journal_row.id
                      AND account.account_code = 'internal_credit_shadow_offset'
                ) AND journal_row.event_type <> 'internal_credit_shadow_v1' THEN
                    RAISE EXCEPTION 'only internal_credit_shadow_v1 may post to the shadow offset account';
                END IF;

                IF journal_row.event_type <> 'internal_credit_shadow_v1' THEN
                    RETURN;
                END IF;

                SELECT COUNT(*), MIN(id)
                INTO link_count, link_id
                FROM custody_journal_source_links
                WHERE custody_journal_entry_id = journal_row.id;

                IF link_count <> 1 THEN
                    RAISE EXCEPTION 'custody Phase 2A journal % requires exactly one source link', journal_row.id;
                END IF;

                PERFORM custody_phase2a_validate_link(link_id);
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_source(target_source_id bigint)
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                source_row merchant_settlement_entries%ROWTYPE;
                link_id bigint;
                link_count bigint;
                cutover_exists boolean;
            BEGIN
                SELECT * INTO source_row
                FROM merchant_settlement_entries
                WHERE id = target_source_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT COUNT(*), MIN(id)
                INTO link_count, link_id
                FROM custody_journal_source_links
                WHERE merchant_settlement_entry_id = source_row.id;

                IF link_count > 0 THEN
                    PERFORM custody_phase2a_validate_link(link_id);
                END IF;

                SELECT EXISTS (
                    SELECT 1
                    FROM custody_phase2a_cutovers
                    WHERE phase_key = 'internal_credit_shadow_v1'
                ) INTO cutover_exists;

                IF cutover_exists
                    AND source_row.type = 'internal_credit'
                    AND source_row.status = 'completed'
                    AND link_count <> 1
                THEN
                    RAISE EXCEPTION 'post-cutover completed internal-credit source % requires exactly one link',
                        source_row.id;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_cutover(target_phase_key text)
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                cutover_row custody_phase2a_cutovers%ROWTYPE;
                expected_baseline jsonb := '{
                    "baseline_schema_version":"custody_phase2a_zero_parity_baseline_v1",
                    "counts":{
                        "custody_account_balances":0,
                        "custody_accounts":0,
                        "custody_journal_entries":0,
                        "custody_journal_postings":0,
                        "custody_journal_source_links":0,
                        "custody_phase2a_cutovers":0,
                        "merchant_balances":0,
                        "merchant_settlement_entries":0
                    },
                    "parity":{
                        "completed_internal_credit_count":0,
                        "covered_internal_credit_count":0,
                        "projection_drift_count":0,
                        "source_snapshot_mutation_count":0,
                        "uncovered_internal_credit_count":0,
                        "unexplained_legacy_residual_count":0
                    }
                }'::jsonb;
                expected_config jsonb := '{
                    "custody_accounting_enabled":true,
                    "custody_invoice_routing_enabled":false,
                    "custody_journal_writes_enabled":true,
                    "custody_phase2a_shadow_internal_credits_enabled":true,
                    "payout_automatic_requests_enabled":false,
                    "payout_execution_enabled":false,
                    "payout_requests_enabled":false
                }'::jsonb;
                dirty_table text;
            BEGIN
                SELECT * INTO cutover_row
                FROM custody_phase2a_cutovers
                WHERE phase_key = target_phase_key;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                IF target_phase_key <> 'internal_credit_shadow_v1'
                    OR cutover_row.baseline_verification_canonical_text::jsonb <> expected_baseline
                    OR cutover_row.activation_config_canonical_text::jsonb <> expected_config
                    OR cutover_row.baseline_verification_canonical_text
                        <> custody_phase2a_canonical_json_text(expected_baseline)
                    OR cutover_row.activation_config_canonical_text
                        <> custody_phase2a_canonical_json_text(expected_config)
                THEN
                    RAISE EXCEPTION 'custody Phase 2A cutover payload is not the exact approved baseline/config';
                END IF;

                SELECT table_name INTO dirty_table
                FROM (
                    SELECT 'merchant_balances' AS table_name
                    WHERE EXISTS (SELECT 1 FROM merchant_balances)
                    UNION ALL
                    SELECT 'merchant_settlement_entries'
                    WHERE EXISTS (SELECT 1 FROM merchant_settlement_entries)
                    UNION ALL
                    SELECT 'custody_accounts'
                    WHERE EXISTS (SELECT 1 FROM custody_accounts)
                    UNION ALL
                    SELECT 'custody_journal_entries'
                    WHERE EXISTS (SELECT 1 FROM custody_journal_entries)
                    UNION ALL
                    SELECT 'custody_journal_postings'
                    WHERE EXISTS (SELECT 1 FROM custody_journal_postings)
                    UNION ALL
                    SELECT 'custody_account_balances'
                    WHERE EXISTS (SELECT 1 FROM custody_account_balances)
                    UNION ALL
                    SELECT 'custody_journal_source_links'
                    WHERE EXISTS (SELECT 1 FROM custody_journal_source_links)
                ) dirty
                LIMIT 1;

                IF dirty_table IS NOT NULL THEN
                    RAISE EXCEPTION 'custody Phase 2A cutover baseline is dirty: %', dirty_table;
                END IF;

                IF (SELECT COUNT(*) FROM custody_phase2a_cutovers) <> 1 THEN
                    RAISE EXCEPTION 'custody Phase 2A cutover marker must be a singleton';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_entry_trigger()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_TABLE_NAME = 'custody_journal_entries' THEN
                    PERFORM custody_phase2a_validate_entry(
                        CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END
                    );
                ELSE
                    IF TG_OP = 'UPDATE' AND OLD.journal_entry_id <> NEW.journal_entry_id THEN
                        PERFORM custody_phase2a_validate_entry(OLD.journal_entry_id);
                    END IF;

                    PERFORM custody_phase2a_validate_entry(
                        CASE WHEN TG_OP = 'DELETE' THEN OLD.journal_entry_id ELSE NEW.journal_entry_id END
                    );
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_link_trigger()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    PERFORM custody_phase2a_validate_source(OLD.merchant_settlement_entry_id);
                    PERFORM custody_phase2a_validate_entry(OLD.custody_journal_entry_id);
                END IF;

                PERFORM custody_phase2a_validate_source(
                    CASE WHEN TG_OP = 'DELETE' THEN OLD.merchant_settlement_entry_id
                         ELSE NEW.merchant_settlement_entry_id END
                );
                PERFORM custody_phase2a_validate_entry(
                    CASE WHEN TG_OP = 'DELETE' THEN OLD.custody_journal_entry_id
                         ELSE NEW.custody_journal_entry_id END
                );

                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_source_trigger()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.id <> NEW.id THEN
                    PERFORM custody_phase2a_validate_source(OLD.id);
                END IF;

                PERFORM custody_phase2a_validate_source(
                    CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END
                );

                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_phase2a_validate_cutover_trigger()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                PERFORM custody_phase2a_validate_cutover(NEW.phase_key);

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER custody_phase2a_entry_valid_at_commit
            AFTER INSERT OR UPDATE ON custody_journal_entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_validate_entry_trigger();

            CREATE CONSTRAINT TRIGGER custody_phase2a_posting_valid_at_commit
            AFTER INSERT OR UPDATE OR DELETE ON custody_journal_postings
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_validate_entry_trigger();

            CREATE CONSTRAINT TRIGGER custody_phase2a_link_valid_at_commit
            AFTER INSERT OR UPDATE OR DELETE ON custody_journal_source_links
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_validate_link_trigger();

            CREATE CONSTRAINT TRIGGER custody_phase2a_source_valid_at_commit
            AFTER INSERT OR UPDATE ON merchant_settlement_entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_validate_source_trigger();

            CREATE CONSTRAINT TRIGGER custody_phase2a_cutover_valid_at_commit
            AFTER INSERT ON custody_phase2a_cutovers
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION custody_phase2a_validate_cutover_trigger();
            SQL);
    }
};
