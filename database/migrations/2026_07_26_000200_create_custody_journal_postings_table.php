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
        Schema::create('custody_journal_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('custody_journal_entries')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('account_id')->constrained('custody_accounts')->restrictOnDelete();
            $table->string('side', 6);
            $table->decimal('amount', 36, 18);
            $table->string('amount_atomic', 120)->nullable();
            $table->timestampTz('created_at');

            $table->unique(['journal_entry_id', 'line_number']);
            $table->index(['account_id', 'journal_entry_id']);
        });

        DB::statement(
            'ALTER TABLE custody_journal_postings
             ALTER COLUMN amount TYPE NUMERIC USING amount::numeric'
        );

        DB::statement(
            "ALTER TABLE custody_journal_postings
             ADD CONSTRAINT custody_journal_postings_side_check
             CHECK (side IN ('debit', 'credit'))"
        );
        DB::statement(
            'ALTER TABLE custody_journal_postings
             ADD CONSTRAINT custody_journal_postings_amount_check
             CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_journal_postings');
    }
};
