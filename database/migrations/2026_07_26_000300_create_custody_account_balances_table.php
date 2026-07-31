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
        Schema::create('custody_account_balances', function (Blueprint $table): void {
            $table->foreignId('account_id')
                ->primary()
                ->constrained('custody_accounts')
                ->restrictOnDelete();
            $table->decimal('balance', 36, 18)->default(0);
            $table->foreignId('last_journal_entry_id')
                ->nullable()
                ->constrained('custody_journal_entries')
                ->restrictOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestampTz('rebuilt_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE custody_account_balances
             ADD CONSTRAINT custody_account_balances_non_negative_check
             CHECK (balance >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_account_balances');
    }
};
