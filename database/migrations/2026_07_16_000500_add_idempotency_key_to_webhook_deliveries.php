<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->string('idempotency_key', 191)->nullable()->after('event');
            $table->unique('idempotency_key', 'webhook_deliveries_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropUnique('webhook_deliveries_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
