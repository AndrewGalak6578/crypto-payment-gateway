<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Exceptions\CustodyAccountingException;
use App\Models\AssetPolicy;
use App\Models\CustodyJournalEntry;
use App\Models\CustodyJournalSourceLink;
use App\Models\CustodyPhase2ACutover;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantBalance;
use App\Models\MerchantSettlementEntry;
use App\Services\Custody\Phase2ACutoverActivator;
use App\Services\Custody\Phase2AGate;
use App\Services\InvoiceForwarder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;
use Throwable;

final class Phase2ACutoverConcurrencyTest extends TestCase
{
    use BuildsDomainData;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableForwardingForTests('phase2a_existing_contract');
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertTrue(function_exists('pcntl_fork'), 'Phase 2A concurrency tests require ext-pcntl.');
        config()->set('webhooks.enabled', false);
    }

    protected function tearDown(): void
    {
        DB::purge();
        DB::reconnect();
        DB::unprepared(<<<'SQL'
            TRUNCATE TABLE
                custody_journal_source_links,
                custody_phase2a_cutovers,
                custody_account_balances,
                custody_journal_postings,
                custody_journal_entries,
                custody_accounts,
                merchant_settlement_entries,
                merchant_balances
            RESTART IDENTITY CASCADE
            SQL);

        parent::tearDown();
    }

    public function test_open_pre_cutover_legacy_credit_blocks_activation_then_dirty_commit_is_rejected(): void
    {
        $this->setPreCutoverLegacyGates();
        $invoice = $this->internalCreditInvoice();
        [$parentSocket, $childSocket] = $this->socketPair();
        $resultPath = $this->resultPath('phase2a-pre-cutover-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            DB::reconnect();
            fread($childSocket, 1);
            $this->setRequiredPhase2AGates();
            file_put_contents($resultPath, "started\n");
            $started = microtime(true);

            try {
                app(Phase2ACutoverActivator::class)->activate('concurrent-dirty-baseline');
                file_put_contents($resultPath, "unexpected_success\n", FILE_APPEND);
                exit(1);
            } catch (CustodyAccountingException $e) {
                $elapsed = microtime(true) - $started;
                file_put_contents(
                    $resultPath,
                    "rejected elapsed={$elapsed} message={$e->getMessage()}\n",
                    FILE_APPEND,
                );
                exit(str_contains($e->getMessage(), 'exact zero/parity baseline') ? 0 : 1);
            } catch (Throwable $e) {
                file_put_contents($resultPath, $e::class.':'.$e->getMessage(), FILE_APPEND);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();

        DB::transaction(function () use ($invoice, $parentSocket, $resultPath): void {
            self::assertFalse(app(Phase2AGate::class)->shadowRequiredForPositiveCredit());
            fwrite($parentSocket, '1');
            fflush($parentSocket);
            $this->waitForFileText($resultPath, 'started');
            usleep(150000);
            self::assertSame(0, CustodyPhase2ACutover::query()->count());

            app(InvoiceForwarder::class)->forward($invoice->id);
        });

        fclose($parentSocket);
        pcntl_waitpid($pid, $status);

        try {
            $result = (string) file_get_contents($resultPath);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), $result);
            self::assertStringContainsString('rejected elapsed=', $result);
            self::assertSame(0, CustodyPhase2ACutover::query()->count());
            self::assertSame(1, MerchantBalance::query()->count());
            self::assertSame(1, MerchantSettlementEntry::query()->count());
            self::assertSame(0, CustodyJournalEntry::query()->count());
        } finally {
            unlink($resultPath);
        }
    }

    public function test_exclusive_activation_blocks_positive_credit_then_credit_observes_marker_and_shadows(): void
    {
        $this->setRequiredPhase2AGates();
        $invoice = $this->internalCreditInvoice();
        [$parentSocket, $childSocket] = $this->socketPair();
        $resultPath = $this->resultPath('phase2a-exclusive-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            DB::reconnect();
            fread($childSocket, 1);
            $this->setRequiredPhase2AGates();
            file_put_contents($resultPath, "started\n");
            $started = microtime(true);

            try {
                app(InvoiceForwarder::class)->forward($invoice->id);
                $elapsed = microtime(true) - $started;
                file_put_contents($resultPath, "completed elapsed={$elapsed}\n", FILE_APPEND);
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($resultPath, $e::class.':'.$e->getMessage(), FILE_APPEND);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();
        DB::beginTransaction();

        try {
            DB::select(
                'SELECT pg_advisory_xact_lock(CAST(? AS bigint))',
                [Phase2AGate::ADVISORY_LOCK_KEY],
            );
            fwrite($parentSocket, '1');
            fflush($parentSocket);
            $this->waitForFileText($resultPath, 'started');
            usleep(150000);
            self::assertSame(0, MerchantSettlementEntry::query()->count());

            app(Phase2ACutoverActivator::class)->activate('concurrent-exclusive-marker');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        fclose($parentSocket);
        pcntl_waitpid($pid, $status);

        try {
            $result = (string) file_get_contents($resultPath);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), $result);
            self::assertStringContainsString('completed elapsed=', $result);
            self::assertSame(1, CustodyPhase2ACutover::query()->count());
            self::assertSame('0.490000000000000000', (string) MerchantBalance::query()->sole()->amount);
            self::assertSame(1, MerchantSettlementEntry::query()->count());
            self::assertSame(1, CustodyJournalEntry::query()->count());
            self::assertSame(1, CustodyJournalSourceLink::query()->count());
        } finally {
            unlink($resultPath);
        }
    }

    public function test_shared_phase_locks_are_compatible_then_merchant_lock_serializes_scope(): void
    {
        $this->setRequiredPhase2AGates();
        app(Phase2ACutoverActivator::class)->activate('shared-lock-compatibility');
        $merchant = $this->createMerchant();
        [$parentSocket, $childSocket] = $this->socketPair();
        $resultPath = $this->resultPath('phase2a-shared-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            DB::reconnect();
            fread($childSocket, 1);
            $this->setRequiredPhase2AGates();

            try {
                DB::transaction(function () use ($merchant, $resultPath): void {
                    self::assertTrue(app(Phase2AGate::class)->shadowRequiredForPositiveCredit());
                    file_put_contents($resultPath, "shared\n", FILE_APPEND);
                    Merchant::query()->lockForUpdate()->findOrFail($merchant->id);
                    file_put_contents($resultPath, "merchant\n", FILE_APPEND);
                });
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($resultPath, $e::class.':'.$e->getMessage(), FILE_APPEND);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();
        DB::beginTransaction();

        try {
            self::assertTrue(app(Phase2AGate::class)->shadowRequiredForPositiveCredit());
            Merchant::query()->lockForUpdate()->findOrFail($merchant->id);
            fwrite($parentSocket, '1');
            fflush($parentSocket);
            $this->waitForFileText($resultPath, 'shared');
            usleep(150000);
            self::assertStringNotContainsString('merchant', (string) file_get_contents($resultPath));
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        fclose($parentSocket);
        pcntl_waitpid($pid, $status);

        try {
            $result = (string) file_get_contents($resultPath);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), $result);
            self::assertStringContainsString("shared\nmerchant\n", $result);
        } finally {
            unlink($resultPath);
        }
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);

        return $sockets;
    }

    private function resultPath(string $prefix): string
    {
        $path = tempnam('/tmp', $prefix);
        self::assertIsString($path);

        return $path;
    }

    private function waitForFileText(string $path, string $needle): void
    {
        for ($attempt = 0; $attempt < 80; $attempt++) {
            if (str_contains((string) file_get_contents($path), $needle)) {
                return;
            }
            usleep(25000);
        }

        self::fail("Timed out waiting for [{$needle}] in concurrency result.");
    }

    private function internalCreditInvoice(): Invoice
    {
        $merchant = $this->createMerchant(['fee_percent' => '2.00']);
        AssetPolicy::query()->create([
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'asset_enabled' => true,
            'checkout_enabled' => true,
            'forwarding_enabled' => true,
            'settlement_mode' => AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
        ]);

        return $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => '0.50000000',
            'received_all_coin' => '0.50000000',
            'fee_coin' => '0.01000000',
            'merchant_payout_coin' => '0.49000000',
            'merchant_payout_usd' => '4900.00',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forwarded_coin' => '0.00000000',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
    }

    private function setPreCutoverLegacyGates(): void
    {
        config()->set('custody.accounting_enabled', false);
        config()->set('custody.journal_writes_enabled', false);
        config()->set('custody.phase2a_shadow_internal_credits_enabled', false);
        config()->set('custody.invoice_routing_enabled', false);
        config()->set('custody.payout_requests_enabled', false);
        config()->set('custody.payout_automatic_requests_enabled', false);
        config()->set('custody.payout_execution_enabled', false);
    }

    private function setRequiredPhase2AGates(): void
    {
        config()->set('custody.accounting_enabled', true);
        config()->set('custody.journal_writes_enabled', true);
        config()->set('custody.phase2a_shadow_internal_credits_enabled', true);
        config()->set('custody.invoice_routing_enabled', false);
        config()->set('custody.payout_requests_enabled', false);
        config()->set('custody.payout_automatic_requests_enabled', false);
        config()->set('custody.payout_execution_enabled', false);
    }
}
