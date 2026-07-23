<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evm_gas_fundings', function (Blueprint $table): void {
            $table->timestampTz('last_reconciled_at')->nullable();
            $table->unsignedInteger('reconciliation_attempts')->default(0);
            $table->timestampTz('next_reconciliation_at')->nullable();
            $table->uuid('reconciliation_owner_token')->nullable();
            $table->timestampTz('reconciliation_lease_expires_at')->nullable();
            $table->timestampTz('continuation_dispatched_at')->nullable();

            $table->index(
                ['state', 'next_reconciliation_at'],
                'evm_gas_fundings_state_next_reconciliation_index'
            );
        });

        DB::table('evm_gas_fundings')
            ->orderBy('id')
            ->eachById(function ($row): void {
                $txHash = trim((string) ($row->tx_hash ?? ''));
                $status = strtolower((string) $row->status);
                $currentState = strtolower((string) ($row->state ?? ''));
                $state = match (true) {
                    ($currentState === 'confirmed' || in_array($status, ['confirmed', 'completed', 'success'], true))
                        && $txHash !== '' => 'confirmed',
                    $currentState === 'needs_reconciliation'
                        || in_array($status, ['needs_reconciliation', 'failed'], true) => 'needs_reconciliation',
                    in_array($status, ['submitted', 'broadcasted', 'funded'], true) && $txHash !== '' => 'broadcasted',
                    default => 'needs_reconciliation',
                };

                DB::table('evm_gas_fundings')
                    ->where('id', $row->id)
                    ->update([
                        'funding_uuid' => $row->funding_uuid ?: (string) Str::uuid(),
                        'state' => $state,
                        'retry_safe' => false,
                        'reconciliation_required_at' => $state === 'needs_reconciliation'
                            ? ($row->reconciliation_required_at ?? now('UTC'))
                            : $row->reconciliation_required_at,
                    ]);
            });

        Schema::table('evm_gas_fundings', function (Blueprint $table): void {
            $table->uuid('funding_uuid')->nullable(false)->change();
        });

        $this->dropActiveNonceIndex();
        $this->createActiveNonceIndex();

        DB::table('evm_gas_fundings')
            ->whereIn('state', ['broadcasting', 'broadcasted', 'needs_reconciliation'])
            ->whereNull('next_reconciliation_at')
            ->update(['next_reconciliation_at' => now('UTC')]);
    }

    public function down(): void
    {
        Schema::table('evm_gas_fundings', function (Blueprint $table): void {
            $table->uuid('funding_uuid')->nullable()->change();
        });

        Schema::table('evm_gas_fundings', function (Blueprint $table): void {
            $table->dropIndex('evm_gas_fundings_state_next_reconciliation_index');
            $table->dropColumn([
                'last_reconciled_at',
                'reconciliation_attempts',
                'next_reconciliation_at',
                'reconciliation_owner_token',
                'reconciliation_lease_expires_at',
                'continuation_dispatched_at',
            ]);
        });
    }

    private function createActiveNonceIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX evm_gas_fundings_network_source_nonce_unique
            ON evm_gas_fundings (network_key, source_address, nonce)
            WHERE nonce IS NOT NULL
              AND state IN ('reserved', 'broadcasting', 'broadcasted', 'needs_reconciliation')
            SQL);
    }

    private function dropActiveNonceIndex(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP INDEX evm_gas_fundings_network_source_nonce_unique ON evm_gas_fundings');

            return;
        }

        DB::statement('DROP INDEX IF EXISTS evm_gas_fundings_network_source_nonce_unique');
    }
};
