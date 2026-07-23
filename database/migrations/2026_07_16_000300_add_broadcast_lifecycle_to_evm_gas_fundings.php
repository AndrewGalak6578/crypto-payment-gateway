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
            $table->string('tx_hash', 191)->nullable()->change();
            $table->uuid('funding_uuid')->nullable()->unique()->after('id');
            $table->string('state', 32)->default('needs_reconciliation')->after('status');
            $table->boolean('retry_safe')->default(false)->after('state');
            $table->string('chain_id', 78)->nullable();
            $table->string('nonce', 78)->nullable();
            $table->unsignedInteger('required_confirmations')->default(1);
            $table->unsignedBigInteger('broadcast_block_number')->nullable();
            $table->char('transaction_fingerprint', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('broadcasting_at')->nullable();
            $table->timestampTz('broadcasted_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('reconciliation_required_at')->nullable();

            $table->index(['state', 'created_at'], 'evm_gas_fundings_state_created_at_index');
        });

        $this->backfillLegacyRows();
        $this->createActiveNonceIndex();
    }

    public function down(): void
    {
        if (DB::table('evm_gas_fundings')->whereNull('tx_hash')->exists()) {
            throw new \RuntimeException(
                'Cannot roll back EVM gas-funding broadcast lifecycle while rows with nullable tx_hash exist. '.
                'Reconcile or archive those rows first.'
            );
        }

        $this->dropActiveNonceIndex();

        Schema::table('evm_gas_fundings', function (Blueprint $table): void {
            $table->dropIndex('evm_gas_fundings_state_created_at_index');
            $table->dropUnique(['funding_uuid']);
            $table->dropColumn([
                'funding_uuid',
                'state',
                'retry_safe',
                'chain_id',
                'nonce',
                'required_confirmations',
                'broadcast_block_number',
                'transaction_fingerprint',
                'error_message',
                'reserved_at',
                'broadcasting_at',
                'broadcasted_at',
                'confirmed_at',
                'failed_at',
                'reconciliation_required_at',
            ]);
            $table->string('tx_hash', 191)->nullable(false)->change();
        });
    }

    private function backfillLegacyRows(): void
    {
        DB::table('evm_gas_fundings')
            ->select(['id', 'funding_uuid', 'status', 'tx_hash'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $status = strtolower((string) $row->status);
                    $txHash = trim((string) ($row->tx_hash ?? ''));

                    $state = match (true) {
                        in_array($status, ['confirmed', 'completed', 'success'], true) && $txHash !== '' => 'confirmed',
                        in_array($status, ['submitted', 'broadcasted', 'funded'], true) && $txHash !== '' => 'broadcasted',
                        default => 'needs_reconciliation',
                    };

                    DB::table('evm_gas_fundings')
                        ->where('id', $row->id)
                        ->update([
                            'funding_uuid' => $row->funding_uuid ?: (string) Str::uuid(),
                            'state' => $state,
                            'retry_safe' => false,
                            'broadcasted_at' => $state === 'broadcasted' ? now('UTC') : null,
                            'confirmed_at' => $state === 'confirmed' ? now('UTC') : null,
                            'reconciliation_required_at' => $state === 'needs_reconciliation' ? now('UTC') : null,
                        ]);
                }
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
