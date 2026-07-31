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
        Schema::create('custody_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('entry_uuid')->unique();
            $table->string('idempotency_key', 191)->unique();
            $table->char('canonical_payload_hash', 64);
            $table->string('event_type', 64);
            $table->foreignId('merchant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_reference', 191)->nullable();
            $table->string('asset_key', 64);
            $table->string('network_key', 64);
            $table->unsignedSmallInteger('asset_scale');
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('custody_journal_entries')
                ->restrictOnDelete();
            $table->string('reason', 96)->nullable();
            $table->jsonb('immutable_metadata')->nullable();
            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['merchant_id', 'asset_key', 'network_key']);
            $table->index(['event_type', 'created_at']);
            $table->index('source_reference');
        });

        DB::statement(
            'ALTER TABLE custody_journal_entries
             ADD CONSTRAINT custody_journal_entries_asset_scale_check
             CHECK (asset_scale BETWEEN 0 AND 18)'
        );
        DB::statement(
            "ALTER TABLE custody_journal_entries
             ADD CONSTRAINT custody_journal_entries_payload_hash_check
             CHECK (canonical_payload_hash ~ '^[0-9a-f]{64}$')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX custody_journal_entries_effective_reversal_unique
             ON custody_journal_entries (reversal_of_id)
             WHERE reversal_of_id IS NOT NULL AND posted_at IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_journal_entries');
    }
};
