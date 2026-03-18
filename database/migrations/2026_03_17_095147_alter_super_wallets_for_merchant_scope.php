<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('super_wallets', function (Blueprint $table) {
            $table->foreignId('merchant_id')
                ->nullable()
                ->after('id')
                ->constrained('merchants')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE super_wallets DROP CONSTRAINT IF EXISTS super_wallets_coin_unique');

        Schema::table('super_wallets', function (Blueprint $table) {
            $table->unique(['merchant_id', 'coin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('super_wallets', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'coin']);
            $table->dropConstrainedForeignId('merchant_id');
        });

        Schema::table('super_wallets', function (Blueprint $table) {
            $table->unique(['coin']);
        });
    }
};
