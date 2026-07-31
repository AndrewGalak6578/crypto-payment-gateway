<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('scope_key', 191);
            $table->foreignId('merchant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('asset_key', 64);
            $table->string('network_key', 64);
            $table->unsignedSmallInteger('asset_scale');
            $table->string('account_code', 64);
            $table->string('normal_side', 6);
            $table->timestampTz('created_at');

            $table->unique(
                ['scope_key', 'asset_key', 'network_key', 'account_code'],
                'custody_accounts_scope_asset_network_code_unique',
            );
            $table->index(['merchant_id', 'asset_key', 'network_key']);
        });

        DB::statement(
            'ALTER TABLE custody_accounts
             ADD CONSTRAINT custody_accounts_asset_scale_check
             CHECK (asset_scale BETWEEN 0 AND 18)'
        );
        DB::statement(
            "ALTER TABLE custody_accounts
             ADD CONSTRAINT custody_accounts_normal_side_check
             CHECK (normal_side IN ('debit', 'credit'))"
        );
        DB::statement(
            "ALTER TABLE custody_accounts
             ADD CONSTRAINT custody_accounts_ownership_check
             CHECK (
                 (
                     account_code IN ('merchant_available', 'merchant_reserved', 'merchant_held')
                     AND merchant_id IS NOT NULL
                     AND scope_key = 'merchant:' || merchant_id::text
                     AND normal_side = 'credit'
                 )
                 OR
                 (
                     account_code IN (
                         'deposit_uncollected',
                         'treasury_available',
                         'treasury_reserved',
                         'outbound',
                         'fee_revenue',
                         'network_fee_expense'
                     )
                     AND merchant_id IS NULL
                     AND scope_key = 'platform'
                     AND normal_side = CASE
                         WHEN account_code = 'fee_revenue' THEN 'credit'
                         ELSE 'debit'
                     END
                 )
                 OR
                 (
                     account_code = 'migration_suspense'
                     AND merchant_id IS NULL
                     AND scope_key = 'migration'
                     AND normal_side = 'debit'
                 )
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_accounts');
    }
};
