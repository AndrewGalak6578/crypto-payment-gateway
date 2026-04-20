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
        Schema::table('payment_addresses', function (Blueprint $table) {
            $table->unique(
                ['network_key', 'key_ref', 'derivation_index'],
                'payment_addresses_network_key_ref_derivation_index_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_addresses', function (Blueprint $table) {
            $table->dropUnique('payment_addresses_network_key_ref_derivation_index_unique');
        });
    }
};
