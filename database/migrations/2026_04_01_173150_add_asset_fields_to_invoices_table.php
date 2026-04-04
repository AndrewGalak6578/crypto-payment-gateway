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
            $table->string('network_key', 64)->nullable()->after('coin');
            $table->string('asset_key', 64)->nullable()->after('network_key');

            $table->index('network_key');
            $table->index('asset_key');
            $table->index(['merchant_id', 'asset_key']);
            $table->index(['merchant_id', 'network_key', 'asset_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'network_key', 'asset_key']);
            $table->dropIndex(['merchant_id', 'asset_key']);
            $table->dropIndex(['asset_key']);
            $table->dropIndex(['network_key']);

            $table->dropColumn([
                'network_key',
                'asset_key',
            ]);
        });
    }
};
