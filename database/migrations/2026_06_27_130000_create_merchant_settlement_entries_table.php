<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settlement_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_key', 64);
            $table->string('network_key', 64)->nullable();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->decimal('amount_coin', 24, 18)->default(0);
            $table->decimal('fee_coin', 24, 18)->nullable();
            $table->decimal('amount_usd', 16, 2)->nullable();
            $table->string('destination_wallet')->nullable();
            $table->string('txid')->nullable();
            $table->string('idempotency_key')->unique();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'occurred_at']);
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'type']);
            $table->index(['invoice_id', 'type']);
            $table->index('txid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settlement_entries');
    }
};
