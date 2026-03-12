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
            $table->decimal('forwarded_coin', 24, 8)->nullable()->after('received_all_coin');
            $table->json('forward_txids')->nullable()->after('forwarded_coin');
            $table->timestampTz('last_forwarded_at')->nullable()->after('forward_txids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'forwarded_coin',
                'forward_txids',
                'last_forwarded_at',
            ]);
        });
    }
};
