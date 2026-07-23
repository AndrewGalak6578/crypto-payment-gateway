<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_key', 64);
            $table->string('network_key', 64);
            $table->boolean('asset_enabled')->default(true);
            $table->boolean('checkout_enabled')->default(true);
            $table->boolean('forwarding_enabled')->default(true);
            $table->string('settlement_mode', 32)->nullable();
            $table->decimal('min_sweep_amount', 36, 18)->nullable();
            $table->decimal('max_gas_cost', 36, 18)->nullable();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['asset_key', 'network_key']);
            $table->index(['asset_enabled', 'checkout_enabled']);
            $table->index(['forwarding_enabled', 'settlement_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_policies');
    }
};
