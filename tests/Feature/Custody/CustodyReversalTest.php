<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Data\CustodyPostingData;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\CustodyAccount;
use App\Models\CustodyJournalEntry;
use App\Models\Merchant;
use App\Services\Custody\CustodyAccountRepository;
use App\Services\Custody\CustodyJournalWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CustodyReversalTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('custody.accounting_enabled', true);
        config()->set('custody.journal_writes_enabled', true);
    }

    public function test_exact_reversal_is_idempotent_and_only_one_can_be_effective(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Custody reversal',
            'status' => 'active',
            'fee_percent' => '1.000',
        ]);
        $accounts = app(CustodyAccountRepository::class);
        $asset = $accounts->platform('btc', 'bitcoin', CustodyAccount::CODE_DEPOSIT_UNCOLLECTED);
        $liability = $accounts->merchant(
            $merchant->id,
            'btc',
            'bitcoin',
            CustodyAccount::CODE_MERCHANT_AVAILABLE,
        );
        $writer = app(CustodyJournalWriter::class);
        $source = $writer->post(new CustodyJournalTransactionData(
            idempotencyKey: 'custody:reversal:source',
            eventType: 'invoice_custody_credited',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            merchantId: $merchant->id,
            postings: [
                new CustodyPostingData($asset->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
                new CustodyPostingData($liability->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
            ],
        ));
        $reversalData = new CustodyJournalTransactionData(
            idempotencyKey: 'custody:reversal:exact',
            eventType: 'custody_correction_reversal',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            merchantId: $merchant->id,
            reversalOfId: $source->id,
            postings: [
                new CustodyPostingData($asset->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
                new CustodyPostingData($liability->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
            ],
        );

        $reversal = $writer->post($reversalData);
        self::assertSame($reversal->id, $writer->post($reversalData)->id);
        self::assertSame(1, CustodyJournalEntry::query()
            ->where('reversal_of_id', $source->id)
            ->whereNotNull('posted_at')
            ->count());
        foreach ([$asset, $liability] as $account) {
            $projection = DB::table('custody_account_balances')->where('account_id', $account->id)->sole();
            self::assertSame('0.000000000000000000', (string) $projection->balance);
            self::assertSame(2, (int) $projection->revision);
            self::assertSame($reversal->id, (int) $projection->last_journal_entry_id);
        }

        $this->expectException(CustodyIdempotencyConflictException::class);
        $writer->post(new CustodyJournalTransactionData(
            idempotencyKey: 'custody:reversal:second',
            eventType: 'custody_correction_reversal',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            merchantId: $merchant->id,
            reversalOfId: $source->id,
            postings: [
                new CustodyPostingData($asset->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
                new CustodyPostingData($liability->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
            ],
        ));
    }
}
