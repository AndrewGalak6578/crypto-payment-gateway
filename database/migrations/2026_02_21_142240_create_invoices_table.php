<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();

            // public id for hosted invoice_page
            $table->string('public_id', 32)->unique();

            // idempotency on merchant side
            $table->string('external_id')->nullable();

            $table->string('status', 20)->default('pending'); // pending|fixated|paid|expired|canceled

            $table->string('coin', 10)->default('dash');
            $table->string('pay_address')->nullable();

            // money snapshot
            $table->decimal('amount_coin', 24, 8)->default(0);
            $table->decimal('expected_usd', 16, 2)->default(0);
            $table->decimal('rate_usd', 20, 8)->default(0);

            // times
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fixated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('monitor_until')->nullable();

            // tx summary
            $table->string('first_txid')->nullable();
            $table->decimal('first_amount_coin', 24, 8)->nullable();

            $table->decimal('received_conf_coin', 24, 8)->default(0);
            $table->decimal('received_all_coin', 24, 8)->default(0);

            // accounting snapshot (later)
            $table->decimal('paid_usd', 16, 2)->nullable();
            $table->decimal('fee_usd', 16, 2)->nullable();
            $table->decimal('merchant_payout_usd', 16, 2)->nullable();

            $table->string('forward_status', 20)->default('none'); // none|partial|done|failed

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index('pay_address');
            $table->index('expires_at');
            $table->index('monitor_until');

            // Postgres: multiple NULL external_id allowed, unique works fine for real ids
            $table->unique(['merchant_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
