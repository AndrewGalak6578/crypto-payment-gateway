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
        Schema::create('payment_address_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->string('network_key', 64);
            $table->string('key_ref', 128);
            $table->unsignedBigInteger('last_issued_index')->default(0);

            $table->timestamps();

            $table->unique(
                ['merchant_id', 'network_key', 'key_ref'],
                'payment_address_sequences_merchant_network_key_ref_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_address_sequences');
    }
};
