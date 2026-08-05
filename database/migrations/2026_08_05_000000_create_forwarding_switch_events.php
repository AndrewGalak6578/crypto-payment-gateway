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
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('forwarding_switch_events requires PostgreSQL.');
        }

        Schema::create('forwarding_switch_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->boolean('enabled');
            $table->string('actor', 191);
            $table->text('reason');
            $table->timestampTz('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE forwarding_switch_events
                ADD CONSTRAINT forwarding_switch_events_actor_nonempty
                    CHECK (btrim(actor) <> ''),
                ADD CONSTRAINT forwarding_switch_events_reason_nonempty
                    CHECK (btrim(reason) <> '')
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_forwarding_switch_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'forwarding_switch_events are append-only';
            END;
            $$;

            CREATE TRIGGER forwarding_switch_events_immutable
            BEFORE UPDATE OR DELETE ON forwarding_switch_events
            FOR EACH ROW
            EXECUTE FUNCTION prevent_forwarding_switch_event_mutation();
            SQL);

        DB::table('forwarding_switch_events')->insert([
            'enabled' => false,
            'actor' => 'system:migration',
            'reason' => 'fail_closed_initialization',
            'created_at' => now('UTC'),
        ]);

        Schema::table('merchant_settlement_attempts', function (Blueprint $table): void {
            $table->uuid('lease_owner_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('merchant_settlement_attempts')->whereNull('lease_owner_token')->exists()) {
            throw new \RuntimeException(
                'Cannot restore non-null settlement lease ownership while cleared pre-broadcast attempts exist.',
            );
        }

        Schema::table('merchant_settlement_attempts', function (Blueprint $table): void {
            $table->uuid('lease_owner_token')->nullable(false)->change();
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS forwarding_switch_events_immutable ON forwarding_switch_events;
            DROP FUNCTION IF EXISTS prevent_forwarding_switch_event_mutation();
            SQL);

        Schema::dropIfExists('forwarding_switch_events');
    }
};
