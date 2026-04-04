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
        Schema::create('payment_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->string('network_key', 64);
            $table->string('asset_key', 64);
            $table->string('address', 191);

            $table->string('family', 32);
            $table->string('address_type', 32)->nullable();
            $table->string('strategy', 64);
            $table->string('status', 32)->default('allocated');

            $table->string('derivation_path', 191)->nullable();
            $table->unsignedBigInteger('derivation_index')->nullable();
            $table->string('key_ref', 128)->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['network_key', 'address'], 'payment_addresses_network_address_unique');

            $table->index(
                ['merchant_id', 'network_key', 'asset_key'],
                'payment_addresses_merchant_network_asset_index',
            );

            $table->index(['invoice_id'], 'payment_addresses_invoice_index');

            $table->index(
                ['status', 'network_key', 'asset_key'],
                'payment_addresses_status_network_asset_index',
            );

            $table->index(
                ['key_ref', 'derivation_path'],
                'payment_addresses_key_ref_derivation_path_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_addresses');
    }
};
