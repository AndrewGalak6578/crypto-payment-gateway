<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Data\CustodyPostingData;
use App\Exceptions\CustodyAccountingException;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\CustodyAccount;
use App\Models\CustodyJournalEntry;
use App\Models\Merchant;
use App\Services\Custody\CustodyAccountRepository;
use App\Services\Custody\CustodyJournalWriter;
use App\Services\Custody\CustodyProjectionRebuilder;
use App\Services\Custody\CustodyProjectionVerifier;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class CustodyJournalTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName());
        config()->set('custody.accounting_enabled', true);
        config()->set('custody.journal_writes_enabled', true);
    }

    public function test_balanced_posting_is_accepted_and_updates_projections_once(): void
    {
        [$merchant, $asset, $liability] = $this->creditAccounts('eth_local');

        $entry = $this->postCredit(
            merchant: $merchant,
            assetAccount: $asset,
            liabilityAccount: $liability,
            amount: '0.123456789012345678',
            key: 'custody:test:balanced',
        );

        self::assertNotNull($entry->posted_at);
        self::assertCount(2, $entry->postings);
        $this->assertProjection($asset->id, '0.123456789012345678', 1, $entry->id);
        $this->assertProjection($liability->id, '0.123456789012345678', 1, $entry->id);
    }

    public function test_commit_time_constraints_reject_missing_unbalanced_and_single_line_entries(): void
    {
        [$merchant, $asset, $liability] = $this->creditAccounts('btc');

        $this->assertConstraintRejected(function (): void {
            $this->insertDraft('custody:test:missing');
        }, 'cannot commit unposted');

        $this->assertConstraintRejected(function () use ($merchant, $asset, $liability): void {
            $entryId = $this->insertDraft('custody:test:unbalanced', merchantId: $merchant->id);
            $this->insertPosting($entryId, 1, $asset->id, CustodyAccount::SIDE_DEBIT, '1.00000000');
            $this->insertPosting($entryId, 2, $liability->id, CustodyAccount::SIDE_CREDIT, '0.50000000');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'unbalanced');

        $this->assertConstraintRejected(function () use ($asset): void {
            $entryId = $this->insertDraft('custody:test:single', posted: false);
            $this->insertPosting($entryId, 1, $asset->id, CustodyAccount::SIDE_DEBIT, '1.00000000');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'requires at least two postings');

        self::assertSame(0, CustodyJournalEntry::query()->count());
        self::assertNotNull($merchant->id);
    }

    public function test_writer_and_database_reject_postings_against_only_one_distinct_account(): void
    {
        $account = app(CustodyAccountRepository::class)->platform(
            'btc',
            'bitcoin',
            CustodyAccount::CODE_DEPOSIT_UNCOLLECTED,
        );
        $transaction = new CustodyJournalTransactionData(
            idempotencyKey: 'custody:test:single-distinct-account-writer',
            eventType: 'single_distinct_account',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            merchantId: null,
            postings: [
                new CustodyPostingData($account->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
                new CustodyPostingData($account->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
            ],
        );

        try {
            app(CustodyJournalWriter::class)->post($transaction);
            self::fail('Writer accepted a journal entry with only one distinct account.');
        } catch (CustodyAccountingException $exception) {
            self::assertStringContainsString('at least two distinct custody accounts', $exception->getMessage());
        }

        self::assertDatabaseMissing('custody_journal_entries', [
            'idempotency_key' => 'custody:test:single-distinct-account-writer',
        ]);
        $this->assertConstraintRejected(function () use ($account): void {
            $entryId = $this->insertDraft('custody:test:single-distinct-account-db');
            $this->insertPosting($entryId, 1, $account->id, CustodyAccount::SIDE_DEBIT, '1.00000000');
            $this->insertPosting($entryId, 2, $account->id, CustodyAccount::SIDE_CREDIT, '1.00000000');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'requires at least two distinct accounts');
    }

    public function test_database_rejects_cross_asset_network_scale_and_excess_precision(): void
    {
        [$merchant, $btcAsset, $btcLiability] = $this->creditAccounts('btc');
        [, $ltcAsset] = $this->creditAccounts('ltc', $merchant);

        $this->assertConstraintRejected(function () use ($btcAsset, $ltcAsset): void {
            $entryId = $this->insertDraft('custody:test:cross-network', false, 'btc', 'bitcoin', 8);
            $this->insertPosting($entryId, 1, $btcAsset->id, CustodyAccount::SIDE_DEBIT, '1.00000000');
            $this->insertPosting($entryId, 2, $ltcAsset->id, CustodyAccount::SIDE_CREDIT, '1.00000000');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'cross-asset, network, or scale');

        $scaleMismatchId = DB::table('custody_accounts')->insertGetId([
            'account_uuid' => (string) Str::uuid(),
            'scope_key' => 'platform',
            'merchant_id' => null,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_scale' => 6,
            'account_code' => CustodyAccount::CODE_TREASURY_AVAILABLE,
            'normal_side' => CustodyAccount::SIDE_DEBIT,
            'created_at' => now('UTC'),
        ]);

        $this->assertConstraintRejected(function () use ($merchant, $btcLiability, $scaleMismatchId): void {
            $entryId = $this->insertDraft('custody:test:cross-scale', merchantId: $merchant->id);
            $this->insertPosting($entryId, 1, $scaleMismatchId, CustodyAccount::SIDE_DEBIT, '1.00000000');
            $this->insertPosting($entryId, 2, $btcLiability->id, CustodyAccount::SIDE_CREDIT, '1.00000000');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'cross-asset, network, or scale');

        [, $usdtAsset, $usdtLiability] = $this->creditAccounts('eth_usdt_local', $merchant);
        $this->assertConstraintRejected(function () use ($merchant, $usdtAsset, $usdtLiability): void {
            $entryId = $this->insertDraft(
                'custody:test:excess-scale',
                false,
                'eth_usdt_local',
                'evm_local',
                6,
                $merchant->id,
            );
            $this->insertPosting($entryId, 1, $usdtAsset->id, CustodyAccount::SIDE_DEBIT, '1.00000010');
            $this->insertPosting($entryId, 2, $usdtLiability->id, CustodyAccount::SIDE_CREDIT, '1.00000010');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'excessive precision');

        $otherMerchant = $this->merchant('Other liability owner');
        [, , $otherLiability] = $this->creditAccounts('btc', $otherMerchant);
        $this->assertConstraintRejected(function () use ($merchant, $btcAsset, $otherLiability): void {
            $entryId = $this->insertDraft(
                idempotencyKey: 'custody:test:cross-merchant',
                merchantId: $merchant->id,
            );
            $this->insertPosting($entryId, 1, $btcAsset->id, CustodyAccount::SIDE_DEBIT, '1.00000000');
            $this->insertPosting($entryId, 2, $otherLiability->id, CustodyAccount::SIDE_CREDIT, '1.00000000');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'owned by another merchant');

        $source = $this->postCredit($merchant, $btcAsset, $btcLiability, '1.00000000', 'custody:test:reversal-source');
        $this->assertConstraintRejected(function () use ($merchant, $btcAsset, $btcLiability, $source): void {
            $entryId = $this->insertDraft(
                idempotencyKey: 'custody:test:partial-reversal',
                merchantId: $merchant->id,
                reversalOfId: $source->id,
            );
            $this->insertPosting($entryId, 1, $btcAsset->id, CustodyAccount::SIDE_CREDIT, '0.50000000');
            $this->insertPosting($entryId, 2, $btcLiability->id, CustodyAccount::SIDE_DEBIT, '0.50000000');
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'does not exactly invert');
    }

    public function test_database_preserves_and_rejects_excess_posting_precision_before_validation(): void
    {
        [$merchant, $asset, $liability] = $this->creditAccounts('eth_local');
        $amount = '1.0000000000000000009';

        $this->assertConstraintRejected(function () use ($merchant, $asset, $liability, $amount): void {
            $entryId = $this->insertDraft(
                idempotencyKey: 'custody:test:eth-excess-precision',
                assetKey: 'eth_local',
                networkKey: 'evm_local',
                assetScale: 18,
                merchantId: $merchant->id,
            );
            $this->insertPosting($entryId, 1, $asset->id, CustodyAccount::SIDE_DEBIT, $amount);
            $this->insertPosting($entryId, 2, $liability->id, CustodyAccount::SIDE_CREDIT, $amount);

            $stored = DB::selectOne(
                'SELECT amount::text AS amount_text, scale(amount) AS amount_scale
                 FROM custody_journal_postings
                 WHERE journal_entry_id = ? AND line_number = 1',
                [$entryId],
            );
            self::assertNotNull($stored);
            self::assertSame($amount, $stored->amount_text);
            self::assertSame(19, (int) $stored->amount_scale);

            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'excessive precision');
    }

    public function test_database_rejects_posting_amounts_with_more_than_eighteen_integer_digits(): void
    {
        [$merchant, $asset, $liability] = $this->creditAccounts('eth_local');

        $this->assertConstraintRejected(function () use ($merchant, $asset, $liability): void {
            $entryId = $this->insertDraft(
                idempotencyKey: 'custody:test:excess-integer-digits',
                assetKey: 'eth_local',
                networkKey: 'evm_local',
                assetScale: 18,
                merchantId: $merchant->id,
            );
            $amount = '1000000000000000000';
            $this->insertPosting($entryId, 1, $asset->id, CustodyAccount::SIDE_DEBIT, $amount);
            $this->insertPosting($entryId, 2, $liability->id, CustodyAccount::SIDE_CREDIT, $amount);
            DB::table('custody_journal_entries')->where('id', $entryId)->update(['posted_at' => now('UTC')]);
        }, 'invalid amount');
    }

    public function test_reversal_preserves_source_merchant_for_platform_only_postings(): void
    {
        $merchant = $this->merchant('Platform source owner');
        $otherMerchant = $this->merchant('Different reversal owner');
        $repository = app(CustodyAccountRepository::class);
        $assetAccount = $repository->platform(
            'btc',
            'bitcoin',
            CustodyAccount::CODE_DEPOSIT_UNCOLLECTED,
        );
        $revenueAccount = $repository->platform(
            'btc',
            'bitcoin',
            CustodyAccount::CODE_FEE_REVENUE,
        );
        $writer = app(CustodyJournalWriter::class);
        $source = $writer->post(new CustodyJournalTransactionData(
            idempotencyKey: 'custody:test:platform-source',
            eventType: 'platform_only_source',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            merchantId: $merchant->id,
            postings: [
                new CustodyPostingData($assetAccount->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
                new CustodyPostingData($revenueAccount->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
            ],
        ));
        $this->assertProjection($assetAccount->id, '1.00000000', 1, $source->id);
        $this->assertProjection($revenueAccount->id, '1.00000000', 1, $source->id);

        try {
            $writer->post(new CustodyJournalTransactionData(
                idempotencyKey: 'custody:test:platform-source-writer-null-merchant',
                eventType: 'custody_correction_reversal',
                assetKey: 'btc',
                networkKey: 'bitcoin',
                merchantId: null,
                reversalOfId: $source->id,
                postings: [
                    new CustodyPostingData($assetAccount->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
                    new CustodyPostingData($revenueAccount->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
                ],
            ));
            self::fail('Writer accepted a reversal with different merchant ownership.');
        } catch (CustodyAccountingException $exception) {
            self::assertStringContainsString('merchant must match', $exception->getMessage());
        }

        foreach ([
            ['custody:test:platform-source-db-null-merchant', null],
            ['custody:test:platform-source-db-other-merchant', $otherMerchant->id],
        ] as [$idempotencyKey, $reversalMerchantId]) {
            $this->assertConstraintRejected(
                function () use (
                    $idempotencyKey,
                    $reversalMerchantId,
                    $source,
                    $assetAccount,
                    $revenueAccount,
                ): void {
                    $entryId = $this->insertDraft(
                        idempotencyKey: $idempotencyKey,
                        merchantId: $reversalMerchantId,
                        reversalOfId: $source->id,
                    );
                    $this->insertPosting(
                        $entryId,
                        1,
                        $assetAccount->id,
                        CustodyAccount::SIDE_CREDIT,
                        '1.00000000',
                    );
                    $this->insertPosting(
                        $entryId,
                        2,
                        $revenueAccount->id,
                        CustodyAccount::SIDE_DEBIT,
                        '1.00000000',
                    );
                    DB::table('custody_journal_entries')
                        ->where('id', $entryId)
                        ->update(['posted_at' => now('UTC')]);
                },
                'reversal merchant must match',
            );
        }

        $reversal = $writer->post(new CustodyJournalTransactionData(
            idempotencyKey: 'custody:test:platform-source-valid-reversal',
            eventType: 'custody_correction_reversal',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            merchantId: $merchant->id,
            reversalOfId: $source->id,
            postings: [
                new CustodyPostingData($assetAccount->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
                new CustodyPostingData($revenueAccount->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
            ],
        ));
        $this->assertProjection($assetAccount->id, '0', 2, $reversal->id);
        $this->assertProjection($revenueAccount->id, '0', 2, $reversal->id);
    }

    public function test_posted_entries_accounts_and_postings_are_append_only(): void
    {
        [$merchant, $asset, $liability] = $this->creditAccounts('btc');
        $entry = $this->postCredit($merchant, $asset, $liability, '1.00000000', 'custody:test:immutable');
        $posting = $entry->postings->firstOrFail();

        $this->assertImmediateMutationRejected(
            fn () => DB::table('custody_journal_entries')
                ->where('id', $entry->id)
                ->update(['reason' => 'changed']),
            'posted custody journal entries are immutable',
        );
        $this->assertImmediateMutationRejected(
            fn () => DB::table('custody_journal_entries')->where('id', $entry->id)->delete(),
            'posted custody journal entries are immutable',
        );
        $this->assertImmediateMutationRejected(
            fn () => DB::table('custody_journal_postings')
                ->where('id', $posting->id)
                ->update(['amount' => '2']),
            'cannot be inserted or changed',
        );
        $this->assertImmediateMutationRejected(
            fn () => DB::table('custody_journal_postings')->where('id', $posting->id)->delete(),
            'cannot be changed or deleted',
        );
        $this->assertImmediateMutationRejected(
            fn () => $this->insertPosting(
                $entry->id,
                3,
                $asset->id,
                CustodyAccount::SIDE_DEBIT,
                '1.00000000',
            ),
            'cannot be inserted or changed',
        );
        $this->assertImmediateMutationRejected(
            fn () => DB::table('custody_accounts')->where('id', $asset->id)->update(['scope_key' => 'changed']),
            'custody accounts are immutable',
        );
        $this->assertImmediateMutationRejected(
            fn () => DB::table('merchants')->where('id', $merchant->id)->delete(),
            'foreign key constraint',
        );
    }

    public function test_identical_idempotency_replay_returns_existing_without_projection_change(): void
    {
        [$merchant, $asset, $liability] = $this->creditAccounts('eth_usdt_local');
        $writer = app(CustodyJournalWriter::class);
        $firstData = $this->creditData(
            $merchant,
            $asset,
            $liability,
            '12.345678',
            'custody:test:idempotency',
            ['b' => 2, 'a' => 1],
        );
        $secondData = $this->creditData(
            $merchant,
            $asset,
            $liability,
            '12.345678',
            'custody:test:idempotency',
            ['a' => 1, 'b' => 2],
        );

        $first = $writer->post($firstData);
        $second = $writer->post($secondData);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, CustodyJournalEntry::query()->count());
        $this->assertProjection($asset->id, '12.345678', 1, $first->id);
        $this->assertProjection($liability->id, '12.345678', 1, $first->id);

        $this->expectException(CustodyIdempotencyConflictException::class);
        $writer->post($this->creditData(
            $merchant,
            $asset,
            $liability,
            '12.345679',
            'custody:test:idempotency',
        ));
    }

    public function test_exact_scales_partial_style_postings_and_deterministic_revisions(): void
    {
        foreach ([
            ['eth_local', '0.000000000000000001'],
            ['btc', '0.00000001'],
            ['eth_usdt_local', '0.000001'],
        ] as [$assetKey, $amount]) {
            [$merchant, $asset, $available] = $this->creditAccounts($assetKey);
            $credit = $this->postCredit(
                $merchant,
                $asset,
                $available,
                $amount,
                "custody:test:scale:{$assetKey}",
            );
            $reserved = app(CustodyAccountRepository::class)->merchant(
                $merchant->id,
                $assetKey,
                $asset->network_key,
                CustodyAccount::CODE_MERCHANT_RESERVED,
            );
            $reserve = app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                idempotencyKey: "custody:test:reserve:{$assetKey}",
                eventType: 'merchant_liability_reserved',
                assetKey: $assetKey,
                networkKey: $asset->network_key,
                merchantId: $merchant->id,
                sourceReference: "test:{$credit->id}",
                postings: [
                    new CustodyPostingData($available->id, CustodyAccount::SIDE_DEBIT, $amount),
                    new CustodyPostingData($reserved->id, CustodyAccount::SIDE_CREDIT, $amount),
                ],
            ));

            $this->assertProjection($available->id, '0', 2, $reserve->id);
            $this->assertProjection($reserved->id, $amount, 1, $reserve->id);
            $this->assertProjection($asset->id, $amount, 1, $credit->id);
        }
    }

    public function test_negative_controlled_projection_is_rejected_atomically(): void
    {
        $merchant = $this->merchant('Negative projection');
        $repository = app(CustodyAccountRepository::class);
        $available = $repository->merchant(
            $merchant->id,
            'btc',
            'bitcoin',
            CustodyAccount::CODE_MERCHANT_AVAILABLE,
        );
        $reserved = $repository->merchant(
            $merchant->id,
            'btc',
            'bitcoin',
            CustodyAccount::CODE_MERCHANT_RESERVED,
        );

        try {
            app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                idempotencyKey: 'custody:test:negative',
                eventType: 'merchant_liability_reserved',
                assetKey: 'btc',
                networkKey: 'bitcoin',
                merchantId: $merchant->id,
                postings: [
                    new CustodyPostingData($available->id, CustodyAccount::SIDE_DEBIT, '1.00000000'),
                    new CustodyPostingData($reserved->id, CustodyAccount::SIDE_CREDIT, '1.00000000'),
                ],
            ));
            self::fail('Negative controlled projection was accepted.');
        } catch (CustodyAccountingException $exception) {
            self::assertStringContainsString('negative', $exception->getMessage());
        }

        self::assertDatabaseMissing('custody_journal_entries', ['idempotency_key' => 'custody:test:negative']);
        $this->assertProjection($available->id, '0', 0, null);
        $this->assertProjection($reserved->id, '0', 0, null);
    }

    public function test_projection_verification_and_rebuild_detect_and_repair_drift(): void
    {
        [$merchant, $asset, $liability] = $this->creditAccounts('btc');
        $entry = $this->postCredit($merchant, $asset, $liability, '2.50000000', 'custody:test:drift');
        DB::table('custody_account_balances')->where('account_id', $liability->id)->update([
            'balance' => '1.00000000',
            'revision' => 99,
            'last_journal_entry_id' => null,
        ]);

        $verifier = app(CustodyProjectionVerifier::class);
        self::assertSame(1, $verifier->verify($merchant->id)['drift_count']);
        $dryRun = app(CustodyProjectionRebuilder::class)->rebuild(false, $merchant->id);
        self::assertSame(1, $dryRun['after']['drift_count']);
        $this->assertProjection($liability->id, '1.00000000', 99, null);

        $write = app(CustodyProjectionRebuilder::class)->rebuild(true, $merchant->id);
        self::assertSame(0, $write['after']['drift_count']);
        $this->assertProjection($liability->id, '2.50000000', 1, $entry->id);
    }

    public function test_concurrent_identical_writes_create_one_entry_and_one_projection_revision(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'Concurrency test requires ext-pcntl.');
        [$merchant, $asset, $liability] = $this->creditAccounts('btc');
        $resultPath = tempnam('/tmp', 'custody-idempotency-');
        self::assertIsString($resultPath);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            DB::reconnect();
            fread($childSocket, 1);

            try {
                $entry = app(CustodyJournalWriter::class)->post($this->creditData(
                    $merchant,
                    $asset,
                    $liability,
                    '3.00000000',
                    'custody:test:concurrent',
                ));
                file_put_contents($resultPath, (string) $entry->id);
                fclose($childSocket);
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultPath, $exception::class.':'.$exception->getMessage());
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();
        fwrite($parentSocket, '1');
        fflush($parentSocket);
        $parentEntry = app(CustodyJournalWriter::class)->post($this->creditData(
            $merchant,
            $asset,
            $liability,
            '3.00000000',
            'custody:test:concurrent',
        ));
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);

        try {
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($resultPath));
            self::assertSame((string) $parentEntry->id, trim((string) file_get_contents($resultPath)));
            self::assertSame(1, CustodyJournalEntry::query()
                ->where('idempotency_key', 'custody:test:concurrent')
                ->count());
            $this->assertProjection($asset->id, '3.00000000', 1, $parentEntry->id);
            $this->assertProjection($liability->id, '3.00000000', 1, $parentEntry->id);
        } finally {
            @unlink($resultPath);
        }
    }

    /**
     * @return array{Merchant, CustodyAccount, CustodyAccount}
     */
    private function creditAccounts(string $assetKey, ?Merchant $merchant = null): array
    {
        $merchant ??= $this->merchant("Custody {$assetKey}");
        $repository = app(CustodyAccountRepository::class);
        $networkKey = app(\App\Support\Assets\AssetRegistry::class)->network($assetKey);

        return [
            $merchant,
            $repository->platform($assetKey, $networkKey, CustodyAccount::CODE_DEPOSIT_UNCOLLECTED),
            $repository->merchant(
                $merchant->id,
                $assetKey,
                $networkKey,
                CustodyAccount::CODE_MERCHANT_AVAILABLE,
            ),
        ];
    }

    private function postCredit(
        Merchant $merchant,
        CustodyAccount $assetAccount,
        CustodyAccount $liabilityAccount,
        string $amount,
        string $key,
    ): CustodyJournalEntry {
        return app(CustodyJournalWriter::class)->post(
            $this->creditData($merchant, $assetAccount, $liabilityAccount, $amount, $key),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function creditData(
        Merchant $merchant,
        CustodyAccount $assetAccount,
        CustodyAccount $liabilityAccount,
        string $amount,
        string $key,
        array $metadata = [],
    ): CustodyJournalTransactionData {
        return new CustodyJournalTransactionData(
            idempotencyKey: $key,
            eventType: 'invoice_custody_credited',
            assetKey: $assetAccount->asset_key,
            networkKey: $assetAccount->network_key,
            merchantId: $merchant->id,
            sourceReference: 'test:invoice:1',
            reason: 'test_credit',
            immutableMetadata: $metadata,
            postings: [
                new CustodyPostingData(
                    $assetAccount->id,
                    CustodyAccount::SIDE_DEBIT,
                    $amount,
                ),
                new CustodyPostingData(
                    $liabilityAccount->id,
                    CustodyAccount::SIDE_CREDIT,
                    $amount,
                ),
            ],
        );
    }

    private function merchant(string $name): Merchant
    {
        return Merchant::query()->create([
            'name' => $name,
            'status' => 'active',
            'fee_percent' => '1.000',
        ]);
    }

    private function insertDraft(
        string $idempotencyKey,
        bool $posted = false,
        string $assetKey = 'btc',
        string $networkKey = 'bitcoin',
        int $assetScale = 8,
        ?int $merchantId = null,
        ?int $reversalOfId = null,
    ): int {
        return DB::table('custody_journal_entries')->insertGetId([
            'entry_uuid' => (string) Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            'canonical_payload_hash' => str_repeat('a', 64),
            'event_type' => 'constraint_test',
            'merchant_id' => $merchantId,
            'source_reference' => null,
            'asset_key' => $assetKey,
            'network_key' => $networkKey,
            'asset_scale' => $assetScale,
            'reversal_of_id' => $reversalOfId,
            'reason' => null,
            'immutable_metadata' => null,
            'effective_at' => null,
            'posted_at' => $posted ? now('UTC') : null,
            'created_at' => now('UTC'),
        ]);
    }

    private function insertPosting(
        int $entryId,
        int $line,
        int $accountId,
        string $side,
        string $amount,
    ): void {
        DB::table('custody_journal_postings')->insert([
            'journal_entry_id' => $entryId,
            'line_number' => $line,
            'account_id' => $accountId,
            'side' => $side,
            'amount' => $amount,
            'amount_atomic' => null,
            'created_at' => now('UTC'),
        ]);
    }

    private function assertConstraintRejected(Closure $callback, string $message): void
    {
        DB::beginTransaction();
        $exception = null;

        try {
            $callback();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } catch (QueryException $caught) {
            $exception = $caught;
        } finally {
            DB::rollBack();
        }

        self::assertNotNull($exception, 'Expected PostgreSQL deferred constraint rejection.');
        self::assertStringContainsString($message, $exception->getMessage());
    }

    private function assertImmediateMutationRejected(Closure $callback, string $message): void
    {
        DB::beginTransaction();
        $exception = null;

        try {
            $callback();
        } catch (QueryException $caught) {
            $exception = $caught;
        } finally {
            DB::rollBack();
        }

        self::assertNotNull($exception, 'Expected PostgreSQL append-only trigger rejection.');
        self::assertStringContainsString($message, $exception->getMessage());
    }

    private function assertProjection(
        int $accountId,
        string $balance,
        int $revision,
        ?int $lastEntryId,
    ): void {
        $row = DB::table('custody_account_balances')->where('account_id', $accountId)->sole();

        self::assertSame(
            app(\App\Services\Custody\CustodyDecimal::class)->storage($balance),
            (string) $row->balance,
        );
        self::assertSame($revision, (int) $row->revision);
        self::assertSame($lastEntryId, $row->last_journal_entry_id === null
            ? null
            : (int) $row->last_journal_entry_id);
    }
}
