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
        Schema::create('super_wallets', function (Blueprint $table) {
            $table->id();

            $table->string('coin', 10)->default('dash');
            $table->string('wallet');
            $table->decimal('fee_rate', 16, 8)->nullable();

            $table->timestamps();

            $table->unique('coin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('super_wallets');
    }
};
