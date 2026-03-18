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
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('fee_coin', 24, 8)->nullable()->after('paid_usd');
            $table->decimal('merchant_payout_coin', 24, 8)->nullable()->after('fee_coin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'fee_coin',
                'merchant_payout_coin'
            ]);
        });
    }
};
