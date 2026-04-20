<?php

declare(strict_types=1);

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
        Schema::create('evm_gas_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('network_key', 64);
            $table->string('asset_key', 64)->nullable();
            $table->string('source_address', 191);
            $table->string('target_address', 191);
            $table->string('amount_native_wei', 120);
            $table->string('tx_hash', 191);
            $table->string('status', 32)->default('submitted');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['network_key', 'target_address'], 'evm_gas_fundings_network_target_index');
            $table->index(['invoice_id', 'created_at'], 'evm_gas_fundings_invoice_created_at_index');
            $table->index(['status', 'created_at'], 'evm_gas_fundings_status_created_at_index');
            $table->unique('tx_hash', 'evm_gas_fundings_tx_hash_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evm_gas_fundings');
    }
};
