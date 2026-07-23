<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settlement_entries', function (Blueprint $table): void {
            $table->dropForeign(['merchant_id']);
            $table->dropForeign(['invoice_id']);
        });

        Schema::table('merchant_settlement_entries', function (Blueprint $table): void {
            $table->foreign('merchant_id')->references('id')->on('merchants')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreignId('settlement_attempt_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('merchant_settlement_attempts')
                ->restrictOnDelete();
            $table->decimal('amount_coin', 36, 18)->default(0)->change();
            $table->decimal('fee_coin', 36, 18)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settlement_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('settlement_attempt_id');
            $table->dropForeign(['merchant_id']);
            $table->dropForeign(['invoice_id']);
        });

        Schema::table('merchant_settlement_entries', function (Blueprint $table): void {
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->decimal('amount_coin', 24, 18)->default(0)->change();
            $table->decimal('fee_coin', 24, 18)->nullable()->change();
        });
    }
};
