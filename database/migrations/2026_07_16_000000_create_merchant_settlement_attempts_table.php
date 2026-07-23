<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settlement_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('asset_key', 64);
            $table->string('network_key', 64);
            $table->string('chain_family', 32);
            $table->string('transfer_type', 32);
            $table->string('state', 32);
            $table->boolean('retry_safe')->default(false);
            $table->decimal('amount_coin', 36, 18);
            $table->decimal('prepared_amount_coin', 36, 18)->nullable();
            $table->decimal('broadcast_amount_coin', 36, 18)->nullable();
            $table->decimal('fee_coin_snapshot', 36, 18);
            $table->decimal('merchant_payout_coin_snapshot', 36, 18);
            $table->string('source_address')->nullable();
            $table->string('source_reference')->nullable();
            $table->string('destination_address');
            $table->string('token_contract')->nullable();
            $table->string('nonce', 78)->nullable();
            $table->string('chain_id', 78)->nullable();
            $table->unsignedInteger('required_confirmations')->default(1);
            $table->unsignedBigInteger('broadcast_block_number')->nullable();
            $table->string('atomic_amount', 120)->nullable();
            $table->text('calldata')->nullable();
            $table->char('calldata_fingerprint', 64)->nullable();
            $table->string('txid')->nullable();
            $table->string('broadcast_reference')->unique();
            $table->char('transaction_fingerprint', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('reserved_at');
            $table->timestampTz('broadcasting_at')->nullable();
            $table->timestampTz('broadcasted_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('reconciliation_required_at')->nullable();
            $table->timestampTz('last_reconciled_at')->nullable();
            $table->unsignedInteger('reconciliation_attempts')->default(0);
            $table->timestampTz('next_reconciliation_at')->nullable();
            $table->uuid('reconciliation_owner_token')->nullable();
            $table->timestampTz('reconciliation_lease_expires_at')->nullable();
            $table->uuid('lease_owner_token');
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'state']);
            $table->index(['state', 'reconciliation_required_at']);
            $table->index(['state', 'next_reconciliation_at']);
            $table->index(['state', 'lease_expires_at']);
            $table->index(['network_key', 'txid']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settlement_attempts_network_source_nonce_unique
            ON merchant_settlement_attempts (network_key, source_address, nonce)
            WHERE source_address IS NOT NULL
              AND nonce IS NOT NULL
              AND state IN ('broadcasting', 'broadcasted', 'confirmed', 'completed', 'needs_reconciliation')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settlement_attempts');
    }
};
