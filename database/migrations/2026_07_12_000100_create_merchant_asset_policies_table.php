<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_asset_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key', 160);
            $table->string('asset_key', 64)->nullable();
            $table->string('network_key', 64)->nullable();
            $table->boolean('asset_enabled')->nullable();
            $table->boolean('checkout_enabled')->nullable();
            $table->boolean('forwarding_enabled')->nullable();
            $table->string('settlement_mode', 32)->nullable();
            $table->decimal('min_sweep_amount', 36, 18)->nullable();
            $table->decimal('max_gas_cost', 36, 18)->nullable();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'scope_key']);
            $table->index(['merchant_id', 'asset_key', 'network_key']);
            $table->index(['merchant_id', 'forwarding_enabled']);
            $table->index(['merchant_id', 'checkout_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_asset_policies');
    }
};
