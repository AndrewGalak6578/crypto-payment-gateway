<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EvmGasFundingLifecycleMigrationTest extends TestCase
{
    private string $databasePath;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam('/tmp', 'settlane-gas-migration-');
        self::assertIsString($path);
        $this->databasePath = $path;
        $this->originalConnection = (string) config('database.default');
        config()->set('database.connections.settlement_migration_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'settlement_migration_test');
        DB::purge('settlement_migration_test');

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('settlement_migration_test');
        DB::purge('settlement_migration_test');
        config()->set('database.default', $this->originalConnection);
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_legacy_row_upgrade_and_non_ambiguous_rollback_use_isolated_database(): void
    {
        $base = $this->migration('2026_04_20_120300_create_evm_gas_fundings_table.php');
        $lifecycle = $this->migration('2026_07_16_000300_add_broadcast_lifecycle_to_evm_gas_fundings.php');
        $schedule = $this->migration('2026_07_16_000400_add_reconciliation_schedule_to_evm_gas_fundings.php');
        $base->up();
        DB::table('evm_gas_fundings')->insert($this->legacyRow('submitted', '0x'.str_repeat('a', 64)));

        $lifecycle->up();
        $schedule->up();

        $row = DB::table('evm_gas_fundings')->first();
        self::assertSame('broadcasted', $row->state);
        self::assertNotEmpty($row->funding_uuid);
        self::assertNotNull($row->next_reconciliation_at);

        $schedule->down();
        $lifecycle->down();
        self::assertFalse(Schema::hasColumn('evm_gas_fundings', 'state'));
        self::assertFalse(Schema::hasColumn('evm_gas_fundings', 'funding_uuid'));
    }

    public function test_rollback_rejects_nullable_transaction_hash_instead_of_destroying_ambiguity(): void
    {
        $base = $this->migration('2026_04_20_120300_create_evm_gas_fundings_table.php');
        $lifecycle = $this->migration('2026_07_16_000300_add_broadcast_lifecycle_to_evm_gas_fundings.php');
        $base->up();
        DB::table('evm_gas_fundings')->insert($this->legacyRow('submitted', '0x'.str_repeat('b', 64)));
        $lifecycle->up();
        DB::table('evm_gas_fundings')->insert(array_merge(
            $this->legacyRow('needs_reconciliation', null),
            [
                'funding_uuid' => 'de8c7fb0-877d-4a08-b514-9b6d54d713b0',
                'state' => 'needs_reconciliation',
                'retry_safe' => false,
            ],
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot roll back EVM gas-funding broadcast lifecycle');

        $lifecycle->down();
    }

    private function migration(string $file): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/'.$file);

        return $migration;
    }

    /** @return array<string, mixed> */
    private function legacyRow(string $status, ?string $txHash): array
    {
        return [
            'invoice_id' => null,
            'network_key' => 'evm_local',
            'asset_key' => 'eth_usdt_local',
            'source_address' => '0x1111111111111111111111111111111111111111',
            'target_address' => '0x2222222222222222222222222222222222222222',
            'amount_native_wei' => '250000000000000',
            'tx_hash' => $txHash,
            'status' => $status,
            'meta' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ];
    }
}
