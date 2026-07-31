<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CustodyAccountingMigrationTest extends TestCase
{
    private string $originalConnection;

    private string $schemaName;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->originalConnection = (string) config('database.default');
        $this->schemaName = 'custody_migration_'.str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
        DB::connection($this->originalConnection)->statement("CREATE SCHEMA {$this->schemaName}");

        $connection = config("database.connections.{$this->originalConnection}");
        self::assertIsArray($connection);
        $connection['search_path'] = $this->schemaName;
        config()->set('database.connections.custody_migration_test', $connection);
        config()->set('database.default', 'custody_migration_test');
        DB::purge('custody_migration_test');

        Schema::create('merchants', function (Blueprint $table): void {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('custody_migration_test');
        DB::purge('custody_migration_test');
        config()->set('database.default', $this->originalConnection);
        DB::connection($this->originalConnection)->statement("DROP SCHEMA IF EXISTS {$this->schemaName} CASCADE");

        parent::tearDown();
    }

    public function test_clean_upgrade_and_reverse_order_rollback_are_safe_in_isolated_postgresql_schema(): void
    {
        $migrations = $this->migrations();
        foreach ($migrations as $migration) {
            $migration->up();
        }
        $migrations[4]->up();

        self::assertTrue(Schema::hasTable('custody_accounts'));
        self::assertTrue(Schema::hasTable('custody_journal_entries'));
        self::assertTrue(Schema::hasTable('custody_journal_postings'));
        self::assertTrue(Schema::hasTable('custody_account_balances'));
        $amountColumn = DB::table('information_schema.columns')
            ->where('table_schema', $this->schemaName)
            ->where('table_name', 'custody_journal_postings')
            ->where('column_name', 'amount')
            ->sole();
        self::assertSame('numeric', $amountColumn->data_type);
        self::assertNull($amountColumn->numeric_precision);
        self::assertNull($amountColumn->numeric_scale);
        self::assertSame(2, DB::table('pg_trigger as t')
            ->join('pg_class as c', 'c.oid', '=', 't.tgrelid')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', $this->schemaName)
            ->whereIn('t.tgname', [
                'custody_journal_entry_valid_at_commit',
                'custody_postings_valid_at_commit',
            ])
            ->count());

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        self::assertFalse(Schema::hasTable('custody_account_balances'));
        self::assertFalse(Schema::hasTable('custody_journal_postings'));
        self::assertFalse(Schema::hasTable('custody_journal_entries'));
        self::assertFalse(Schema::hasTable('custody_accounts'));
        self::assertTrue(Schema::hasTable('merchants'));

        foreach ($migrations as $migration) {
            $migration->up();
        }

        self::assertTrue(Schema::hasTable('custody_account_balances'));

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
    }

    /**
     * @return list<Migration>
     */
    private function migrations(): array
    {
        return array_map(
            fn (string $file): Migration => require database_path("migrations/{$file}"),
            [
                '2026_07_26_000000_create_custody_accounts_table.php',
                '2026_07_26_000100_create_custody_journal_entries_table.php',
                '2026_07_26_000200_create_custody_journal_postings_table.php',
                '2026_07_26_000300_create_custody_account_balances_table.php',
                '2026_07_26_000400_add_custody_journal_integrity_triggers.php',
            ],
        );
    }
}
