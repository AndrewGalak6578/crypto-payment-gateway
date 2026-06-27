<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->foreignId('merchant_id')
                ->nullable()
                ->after('id')
                ->constrained('merchants')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE webhook_deliveries
            SET merchant_id = invoices.merchant_id
            FROM invoices
            WHERE webhook_deliveries.invoice_id = invoices.id
        SQL);

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropForeign(['invoice_id']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id')->nullable()->change();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['merchant_id', 'status']);
            $table->dropForeign(['invoice_id']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id')->nullable(false)->change();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->dropConstrainedForeignId('merchant_id');
        });
    }
};
