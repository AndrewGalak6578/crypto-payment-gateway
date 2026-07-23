<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_balances', function (Blueprint $table): void {
            $table->string('coin', 64)->change();
            $table->decimal('amount', 36, 18)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_balances', function (Blueprint $table): void {
            $table->string('coin', 10)->change();
            $table->decimal('amount', 24, 18)->default(0)->change();
        });
    }
};
