<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Data\CustodyPostingData;
use App\Models\CustodyAccount;
use App\Models\Merchant;
use App\Services\Custody\CustodyAccountRepository;
use App\Services\Custody\CustodyJournalWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CustodyProjectionCommandsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('custody.accounting_enabled', true);
        config()->set('custody.journal_writes_enabled', true);
    }

    public function test_verify_is_read_only_and_rebuild_requires_explicit_write(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Projection command merchant',
            'status' => 'active',
            'fee_percent' => '1.000',
        ]);
        $repository = app(CustodyAccountRepository::class);
        $asset = $repository->platform('btc', 'bitcoin', CustodyAccount::CODE_DEPOSIT_UNCOLLECTED);
        $liability = $repository->merchant(
            $merchant->id,
            'btc',
            'bitcoin',
            CustodyAccount::CODE_MERCHANT_AVAILABLE,
        );
        app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
            idempotencyKey: 'custody:commands:credit',
            eventType: 'invoice_custody_credited',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            merchantId: $merchant->id,
            postings: [
                new CustodyPostingData($asset->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
                new CustodyPostingData($liability->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
            ],
        ));
        DB::table('custody_account_balances')->where('account_id', $liability->id)->update([
            'balance' => '0',
        ]);

        $this->artisan('custody:verify-projections', [
            '--merchant' => (string) $merchant->id,
            '--json' => true,
        ])->assertFailed()->expectsOutputToContain('"drift_count": 1');

        $this->artisan('custody:rebuild-projections', [
            '--merchant' => (string) $merchant->id,
            '--json' => true,
        ])->assertFailed()->expectsOutputToContain('"mode": "dry-run"');
        self::assertSame(
            '0.000000000000000000',
            (string) DB::table('custody_account_balances')->where('account_id', $liability->id)->value('balance'),
        );

        $this->artisan('custody:rebuild-projections', [
            '--merchant' => (string) $merchant->id,
            '--write' => true,
            '--json' => true,
        ])->assertSuccessful()->expectsOutputToContain('"drift_count": 0');
        self::assertSame(
            '1.000000000000000000',
            (string) DB::table('custody_account_balances')->where('account_id', $liability->id)->value('balance'),
        );
        $this->artisan('custody:verify-projections', [
            '--merchant' => (string) $merchant->id,
        ])->assertSuccessful();
    }

    public function test_commands_reject_invalid_merchant_and_unknown_projection_asset_filters(): void
    {
        foreach ([
            'custody:verify-projections',
            'custody:rebuild-projections',
            'custody:reconcile-legacy-balances',
        ] as $command) {
            $this->artisan($command, ['--merchant' => 'not-an-id'])
                ->assertExitCode(2)
                ->expectsOutputToContain('must be a positive base-10 integer');
            $this->artisan($command, ['--merchant' => '0'])
                ->assertExitCode(2)
                ->expectsOutputToContain('must be a positive base-10 integer');
        }

        foreach ([
            'custody:verify-projections',
            'custody:rebuild-projections',
        ] as $command) {
            $this->artisan($command, ['--asset' => 'unknown-custody-asset'])
                ->assertExitCode(2)
                ->expectsOutputToContain('Unknown asset [unknown-custody-asset]');
        }
    }

    public function test_commands_reject_merchant_filter_integer_overflow(): void
    {
        $this->artisan('custody:verify-projections', [
            '--merchant' => '999999999999999999999999999999',
        ])->assertExitCode(2)->expectsOutputToContain('exceeds the supported integer range');
    }
}
