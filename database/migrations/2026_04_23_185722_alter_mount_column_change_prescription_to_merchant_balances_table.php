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
        Schema::table('merchant_balances', function (Blueprint $table) {
            $table->decimal('amount', 24, 18)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_balances', function (Blueprint $table) {
            $table->decimal('amount', 24, 8)->change();
        });
    }
};
