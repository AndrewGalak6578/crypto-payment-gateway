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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('status', 20)->default('active'); // active|disabled

            $table->decimal('fee_percent', 6, 3)->default(0); // 1.500 = 1.5%
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
