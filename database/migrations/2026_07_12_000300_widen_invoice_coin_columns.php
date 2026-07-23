<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('amount_coin', 36, 18)->default(0)->change();
            $table->decimal('first_amount_coin', 36, 18)->nullable()->change();
            $table->decimal('received_conf_coin', 36, 18)->default(0)->change();
            $table->decimal('received_all_coin', 36, 18)->default(0)->change();
            $table->decimal('forwarded_coin', 36, 18)->nullable()->change();
            $table->decimal('forwarding_coin', 36, 18)->nullable()->change();
            $table->decimal('fee_coin', 36, 18)->nullable()->change();
            $table->decimal('merchant_payout_coin', 36, 18)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('amount_coin', 24, 8)->default(0)->change();
            $table->decimal('first_amount_coin', 24, 8)->nullable()->change();
            $table->decimal('received_conf_coin', 24, 8)->default(0)->change();
            $table->decimal('received_all_coin', 24, 8)->default(0)->change();
            $table->decimal('forwarded_coin', 24, 8)->nullable()->change();
            $table->decimal('forwarding_coin', 24, 8)->nullable()->change();
            $table->decimal('fee_coin', 24, 18)->nullable()->change();
            $table->decimal('merchant_payout_coin', 24, 16)->nullable()->change();
        });
    }
};
