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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('event', 50);
            $table->string('url', 1000);
            $table->json('payload');
            $table->string('signature', 255);

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_retry_at')->nullable();

            $table->string('status', 20)->default('pending'); // pending|delivering|delivered|failed
            $table->text('last_error')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index(['invoice_id', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
