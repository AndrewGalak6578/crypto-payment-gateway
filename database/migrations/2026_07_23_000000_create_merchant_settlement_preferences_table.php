<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settlement_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('asset_key', 64);
            $table->string('network_key', 64);
            $table->string('requested_mode', 32)->nullable();
            $table->decimal('requested_minimum_invoice_payout', 36, 18)->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(
                ['merchant_id', 'asset_key', 'network_key'],
                'merchant_settlement_preferences_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settlement_preferences');
    }
};
