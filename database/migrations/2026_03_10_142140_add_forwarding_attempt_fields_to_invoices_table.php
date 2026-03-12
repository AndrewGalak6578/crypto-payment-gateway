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
            $table->uuid('forward_attempt_uuid')->nullable()->after('forward_status');
            $table->decimal('forwarding_coin', 24, 8)->nullable()->after('forward_attempt_uuid');
            $table->timestampTz('forwarding_started_at')->nullable()->after('forward_coin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'forward_attempt_uuid',
                'forwarding_coin',
                'forwarding_started_at',
            ]);
        });
    }
};
