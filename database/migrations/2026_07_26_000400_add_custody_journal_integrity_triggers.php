<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Custody accounting integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION custody_reject_account_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'custody accounts are immutable';
            END;
            $$;

            DROP TRIGGER IF EXISTS custody_accounts_immutable ON custody_accounts;
            CREATE TRIGGER custody_accounts_immutable
            BEFORE UPDATE OR DELETE ON custody_accounts
            FOR EACH ROW EXECUTE FUNCTION custody_reject_account_mutation();

            CREATE OR REPLACE FUNCTION custody_guard_journal_entry_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.posted_at IS NOT NULL THEN
                        RAISE EXCEPTION 'posted custody journal entries are immutable';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.posted_at IS NOT NULL THEN
                    RAISE EXCEPTION 'posted custody journal entries are immutable';
                END IF;

                IF NEW.posted_at IS NULL THEN
                    RAISE EXCEPTION 'only the initial custody journal posting transition is allowed';
                END IF;

                IF (
                    NEW.entry_uuid,
                    NEW.idempotency_key,
                    NEW.canonical_payload_hash,
                    NEW.event_type,
                    NEW.merchant_id,
                    NEW.source_reference,
                    NEW.asset_key,
                    NEW.network_key,
                    NEW.asset_scale,
                    NEW.reversal_of_id,
                    NEW.reason,
                    NEW.immutable_metadata,
                    NEW.effective_at,
                    NEW.created_at
                ) IS DISTINCT FROM (
                    OLD.entry_uuid,
                    OLD.idempotency_key,
                    OLD.canonical_payload_hash,
                    OLD.event_type,
                    OLD.merchant_id,
                    OLD.source_reference,
                    OLD.asset_key,
                    OLD.network_key,
                    OLD.asset_scale,
                    OLD.reversal_of_id,
                    OLD.reason,
                    OLD.immutable_metadata,
                    OLD.effective_at,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'custody journal financial payload cannot change while posting';
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS custody_journal_entries_append_only ON custody_journal_entries;
            CREATE TRIGGER custody_journal_entries_append_only
            BEFORE UPDATE OR DELETE ON custody_journal_entries
            FOR EACH ROW EXECUTE FUNCTION custody_guard_journal_entry_mutation();

            CREATE OR REPLACE FUNCTION custody_guard_posting_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_posted_at timestamptz;
                previous_posted_at timestamptz;
            BEGIN
                IF TG_OP <> 'DELETE' THEN
                    SELECT posted_at INTO target_posted_at
                    FROM custody_journal_entries
                    WHERE id = NEW.journal_entry_id;

                    IF target_posted_at IS NOT NULL THEN
                        RAISE EXCEPTION 'postings cannot be inserted or changed after journal posting';
                    END IF;
                END IF;

                IF TG_OP <> 'INSERT' THEN
                    SELECT posted_at INTO previous_posted_at
                    FROM custody_journal_entries
                    WHERE id = OLD.journal_entry_id;

                    IF previous_posted_at IS NOT NULL THEN
                        RAISE EXCEPTION 'postings cannot be changed or deleted after journal posting';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS custody_journal_postings_append_only ON custody_journal_postings;
            CREATE TRIGGER custody_journal_postings_append_only
            BEFORE INSERT OR UPDATE OR DELETE ON custody_journal_postings
            FOR EACH ROW EXECUTE FUNCTION custody_guard_posting_mutation();

            CREATE OR REPLACE FUNCTION custody_validate_journal_entry(target_entry_id bigint)
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_entry custody_journal_entries%ROWTYPE;
                reversal_entry custody_journal_entries%ROWTYPE;
                posting_count bigint;
                distinct_account_count bigint;
                invalid_account_count bigint;
                invalid_owner_count bigint;
                invalid_amount_count bigint;
                reversal_mismatch_count bigint;
                debit_total numeric;
                credit_total numeric;
            BEGIN
                SELECT * INTO target_entry
                FROM custody_journal_entries
                WHERE id = target_entry_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                IF target_entry.posted_at IS NULL THEN
                    RAISE EXCEPTION 'custody journal entry % cannot commit unposted', target_entry_id;
                END IF;

                SELECT
                    COUNT(*),
                    COUNT(DISTINCT p.account_id),
                    COALESCE(SUM(CASE WHEN p.side = 'debit' THEN p.amount ELSE 0 END), 0),
                    COALESCE(SUM(CASE WHEN p.side = 'credit' THEN p.amount ELSE 0 END), 0),
                    COUNT(*) FILTER (
                        WHERE a.asset_key <> target_entry.asset_key
                           OR a.network_key <> target_entry.network_key
                           OR a.asset_scale <> target_entry.asset_scale
                    ),
                    COUNT(*) FILTER (
                        WHERE a.merchant_id IS NOT NULL
                          AND a.merchant_id IS DISTINCT FROM target_entry.merchant_id
                    ),
                    COUNT(*) FILTER (
                        WHERE p.amount <= 0
                           OR p.amount >= 1000000000000000000
                           OR scale(p.amount) > target_entry.asset_scale
                    )
                INTO
                    posting_count,
                    distinct_account_count,
                    debit_total,
                    credit_total,
                    invalid_account_count,
                    invalid_owner_count,
                    invalid_amount_count
                FROM custody_journal_postings p
                JOIN custody_accounts a ON a.id = p.account_id
                WHERE p.journal_entry_id = target_entry_id;

                IF posting_count < 2 THEN
                    RAISE EXCEPTION 'custody journal entry % requires at least two postings', target_entry_id;
                END IF;

                IF distinct_account_count < 2 THEN
                    RAISE EXCEPTION 'custody journal entry % requires at least two distinct accounts',
                        target_entry_id;
                END IF;

                IF invalid_account_count > 0 THEN
                    RAISE EXCEPTION 'custody journal entry % contains cross-asset, network, or scale postings',
                        target_entry_id;
                END IF;

                IF invalid_owner_count > 0 THEN
                    RAISE EXCEPTION 'custody journal entry % contains a liability account owned by another merchant',
                        target_entry_id;
                END IF;

                IF invalid_amount_count > 0 THEN
                    RAISE EXCEPTION 'custody journal entry % contains an invalid amount or excessive precision',
                        target_entry_id;
                END IF;

                IF debit_total <> credit_total THEN
                    RAISE EXCEPTION 'custody journal entry % is unbalanced', target_entry_id;
                END IF;

                IF target_entry.reversal_of_id IS NOT NULL THEN
                    IF target_entry.reversal_of_id = target_entry.id THEN
                        RAISE EXCEPTION 'custody journal entry cannot reverse itself';
                    END IF;

                    SELECT * INTO reversal_entry
                    FROM custody_journal_entries
                    WHERE id = target_entry.reversal_of_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'custody reversal target does not exist';
                    END IF;

                    IF reversal_entry.posted_at IS NULL THEN
                        RAISE EXCEPTION 'custody reversal target must be posted';
                    END IF;

                    IF reversal_entry.merchant_id IS DISTINCT FROM target_entry.merchant_id THEN
                        RAISE EXCEPTION 'custody reversal merchant must match the target entry merchant';
                    END IF;

                    IF (
                        reversal_entry.asset_key,
                        reversal_entry.network_key,
                        reversal_entry.asset_scale
                    ) IS DISTINCT FROM (
                        target_entry.asset_key,
                        target_entry.network_key,
                        target_entry.asset_scale
                    ) THEN
                        RAISE EXCEPTION 'custody reversal target uses a different asset, network, or scale';
                    END IF;

                    WITH expected AS (
                        SELECT
                            account_id,
                            CASE WHEN side = 'debit' THEN 'credit' ELSE 'debit' END AS side,
                            SUM(amount) AS amount
                        FROM custody_journal_postings
                        WHERE journal_entry_id = target_entry.reversal_of_id
                        GROUP BY account_id, side
                    ),
                    actual AS (
                        SELECT account_id, side, SUM(amount) AS amount
                        FROM custody_journal_postings
                        WHERE journal_entry_id = target_entry.id
                        GROUP BY account_id, side
                    )
                    SELECT COUNT(*) INTO reversal_mismatch_count
                    FROM (
                        (SELECT * FROM expected EXCEPT SELECT * FROM actual)
                        UNION ALL
                        (SELECT * FROM actual EXCEPT SELECT * FROM expected)
                    ) differences;

                    IF reversal_mismatch_count > 0 THEN
                        RAISE EXCEPTION 'custody reversal does not exactly invert the target postings';
                    END IF;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION custody_validate_journal_entry_trigger()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_TABLE_NAME = 'custody_journal_entries' THEN
                    PERFORM custody_validate_journal_entry(
                        CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END
                    );
                ELSE
                    IF TG_OP = 'UPDATE' AND OLD.journal_entry_id <> NEW.journal_entry_id THEN
                        PERFORM custody_validate_journal_entry(OLD.journal_entry_id);
                    END IF;

                    PERFORM custody_validate_journal_entry(
                        CASE WHEN TG_OP = 'DELETE' THEN OLD.journal_entry_id ELSE NEW.journal_entry_id END
                    );
                END IF;

                RETURN NULL;
            END;
            $$;

            DROP TRIGGER IF EXISTS custody_journal_entry_valid_at_commit ON custody_journal_entries;
            CREATE CONSTRAINT TRIGGER custody_journal_entry_valid_at_commit
            AFTER INSERT OR UPDATE ON custody_journal_entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION custody_validate_journal_entry_trigger();

            DROP TRIGGER IF EXISTS custody_postings_valid_at_commit ON custody_journal_postings;
            CREATE CONSTRAINT TRIGGER custody_postings_valid_at_commit
            AFTER INSERT OR UPDATE OR DELETE ON custody_journal_postings
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION custody_validate_journal_entry_trigger();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Custody accounting integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS custody_postings_valid_at_commit ON custody_journal_postings;
            DROP TRIGGER IF EXISTS custody_journal_entry_valid_at_commit ON custody_journal_entries;
            DROP FUNCTION IF EXISTS custody_validate_journal_entry_trigger();
            DROP FUNCTION IF EXISTS custody_validate_journal_entry(bigint);
            DROP TRIGGER IF EXISTS custody_journal_postings_append_only ON custody_journal_postings;
            DROP FUNCTION IF EXISTS custody_guard_posting_mutation();
            DROP TRIGGER IF EXISTS custody_journal_entries_append_only ON custody_journal_entries;
            DROP FUNCTION IF EXISTS custody_guard_journal_entry_mutation();
            DROP TRIGGER IF EXISTS custody_accounts_immutable ON custody_accounts;
            DROP FUNCTION IF EXISTS custody_reject_account_mutation();
            SQL);
    }
};
