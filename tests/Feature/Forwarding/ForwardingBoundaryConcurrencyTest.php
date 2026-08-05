<?php

declare(strict_types=1);

namespace Tests\Feature\Forwarding;

use App\Models\EvmGasFunding;
use App\Models\ForwardingSwitchEvent;
use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Services\Evm\EvmGasFundingBoundary;
use App\Services\Forwarding\ForwardingGate;
use App\Services\Forwarding\ForwardingSwitchManager;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;
use Throwable;

final class ForwardingBoundaryConcurrencyTest extends TestCase
{
    use BuildsDomainData;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertTrue(function_exists('pcntl_fork'), 'Forwarding concurrency tests require ext-pcntl.');
        $this->enableForwardingForTests('concurrency_baseline_enabled');
    }

    protected function tearDown(): void
    {
        DB::purge();
        DB::reconnect();
        DB::statement('TRUNCATE TABLE invoices RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    public function test_disable_linearizes_before_new_reservation(): void
    {
        $invoice = $this->reservableInvoice();
        [$parentSocket, $childSocket] = $this->socketPair();
        $resultPath = $this->resultPath('forward-reserve-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            fread($childSocket, 1);
            DB::purge();
            DB::reconnect();
            fwrite($childSocket, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            fflush($childSocket);

            try {
                $attempt = app(MerchantSettlementAttemptManager::class)->reserve(
                    invoiceId: $invoice->id,
                    chainFamily: 'utxo',
                    transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
                    destinationAddress: 'bcrt1qdisablewinsreservation',
                );
                file_put_contents($resultPath, $attempt === null ? 'closed' : 'unexpected_attempt');
                fclose($childSocket);
                exit($attempt === null ? 0 : 1);
            } catch (Throwable $exception) {
                file_put_contents($resultPath, $exception::class.': '.$exception->getMessage());
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();

        try {
            DB::beginTransaction();
            app(ForwardingGate::class)->acquireExclusiveLock();
            $this->appendDisabledEvent('concurrency:disable_before_reservation');
            fwrite($parentSocket, '1');
            fflush($parentSocket);
            $backendPid = (int) trim((string) fgets($parentSocket));
            $this->waitForAdvisoryWait($backendPid);
            self::assertDatabaseMissing('merchant_settlement_attempts', ['invoice_id' => $invoice->id]);
            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        } finally {
            fclose($parentSocket);
        }

        pcntl_waitpid($pid, $status);

        try {
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($resultPath));
            self::assertSame('closed', (string) file_get_contents($resultPath));
            self::assertDatabaseMissing('merchant_settlement_attempts', ['invoice_id' => $invoice->id]);
            self::assertFalse(ForwardingSwitchEvent::query()->latest('id')->firstOrFail()->enabled);
        } finally {
            unlink($resultPath);
        }
    }

    public function test_boundary_linearizes_before_disable_and_remains_broadcasting(): void
    {
        $invoice = $this->reservableInvoice();
        $ownerToken = (string) Str::uuid();
        $attempt = app(MerchantSettlementAttemptManager::class)->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'utxo',
            transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
            destinationAddress: 'bcrt1qworkerwinsboundary',
            ownerToken: $ownerToken,
        );
        self::assertNotNull($attempt);

        [$parentSocket, $childSocket] = $this->socketPair();
        $resultPath = $this->resultPath('forward-boundary-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            fread($childSocket, 1);
            DB::purge();
            DB::reconnect();
            fwrite($childSocket, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            fflush($childSocket);

            try {
                $change = app(ForwardingSwitchManager::class)->set(
                    false,
                    'test:concurrency',
                    'disable_after_worker_boundary',
                );
                file_put_contents($resultPath, $change->event->enabled ? 'unexpected_enabled' : 'disabled');
                fclose($childSocket);
                exit($change->event->enabled ? 1 : 0);
            } catch (Throwable $exception) {
                file_put_contents($resultPath, $exception::class.': '.$exception->getMessage());
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();

        try {
            DB::beginTransaction();
            app(MerchantSettlementAttemptManager::class)->markBroadcasting(
                attemptId: $attempt->id,
                sourceReference: 'rpc-wallet:bitcoin',
                ownerToken: $ownerToken,
            );
            fwrite($parentSocket, '1');
            fflush($parentSocket);
            $backendPid = (int) trim((string) fgets($parentSocket));
            $this->waitForAdvisoryWait($backendPid);
            self::assertSame(
                MerchantSettlementAttempt::STATE_BROADCASTING,
                MerchantSettlementAttempt::query()->findOrFail($attempt->id)->state,
            );
            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        } finally {
            fclose($parentSocket);
        }

        pcntl_waitpid($pid, $status);

        try {
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($resultPath));
            self::assertSame('disabled', (string) file_get_contents($resultPath));
            self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTING, $attempt->fresh()->state);
            self::assertFalse($attempt->fresh()->retry_safe);
            self::assertFalse(ForwardingSwitchEvent::query()->latest('id')->firstOrFail()->enabled);

            $secondInvoice = $this->reservableInvoice();
            self::assertNull(app(MerchantSettlementAttemptManager::class)->reserve(
                invoiceId: $secondInvoice->id,
                chainFamily: 'utxo',
                transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
                destinationAddress: 'bcrt1qpostdisableboundary',
            ));
            self::assertDatabaseMissing('merchant_settlement_attempts', ['invoice_id' => $secondInvoice->id]);
        } finally {
            unlink($resultPath);
        }
    }

    public function test_disable_linearizes_before_gas_funding_boundary(): void
    {
        $invoice = $this->reservableInvoice('eth_usdt_local', 'evm_local', '10.000000');
        $ownerToken = (string) Str::uuid();
        $attempt = app(MerchantSettlementAttemptManager::class)->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'evm',
            transferType: MerchantSettlementAttempt::TRANSFER_ERC20,
            destinationAddress: '0x4444444444444444444444444444444444444444',
            ownerToken: $ownerToken,
        );
        self::assertNotNull($attempt);
        [$parentSocket, $childSocket] = $this->socketPair();
        $resultPath = $this->resultPath('forward-gas-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            fread($childSocket, 1);
            DB::purge();
            DB::reconnect();
            fwrite($childSocket, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            fflush($childSocket);

            try {
                $funding = app(EvmGasFundingBoundary::class)->begin(
                    invoiceId: $invoice->id,
                    settlementAttemptId: $attempt->id,
                    settlementAttemptUuid: $attempt->attempt_uuid,
                    ownerToken: $ownerToken,
                    attributes: [
                        'funding_uuid' => (string) Str::uuid(),
                        'network_key' => 'evm_local',
                        'asset_key' => 'eth_usdt_local',
                        'source_address' => '0x1111111111111111111111111111111111111111',
                        'target_address' => '0x2222222222222222222222222222222222222222',
                        'amount_native_wei' => '250000',
                        'chain_id' => '31337',
                        'nonce' => '9',
                        'required_confirmations' => 1,
                        'broadcast_block_number' => 100,
                        'transaction_fingerprint' => str_repeat('a', 64),
                        'meta' => ['test' => 'disable_wins'],
                    ],
                );
                file_put_contents($resultPath, $funding === null ? 'closed' : 'unexpected_funding');
                fclose($childSocket);
                exit($funding === null ? 0 : 1);
            } catch (Throwable $exception) {
                file_put_contents($resultPath, $exception::class.': '.$exception->getMessage());
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();

        try {
            DB::beginTransaction();
            app(ForwardingGate::class)->acquireExclusiveLock();
            $this->appendDisabledEvent('concurrency:disable_before_gas_funding');
            fwrite($parentSocket, '1');
            fflush($parentSocket);
            $backendPid = (int) trim((string) fgets($parentSocket));
            $this->waitForAdvisoryWait($backendPid);
            self::assertSame(0, EvmGasFunding::query()->count());
            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        } finally {
            fclose($parentSocket);
        }

        pcntl_waitpid($pid, $status);

        try {
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($resultPath));
            self::assertSame('closed', (string) file_get_contents($resultPath));
            self::assertSame(0, EvmGasFunding::query()->count());
            self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $attempt->fresh()->state);
            self::assertTrue($attempt->fresh()->retry_safe);
            self::assertSame(
                'forwarding_disabled_before_gas_funding',
                $attempt->fresh()->error_message,
            );
            self::assertNull($attempt->fresh()->lease_owner_token);
            self::assertNull($invoice->fresh()->forward_attempt_uuid);
            self::assertFalse(ForwardingSwitchEvent::query()->latest('id')->firstOrFail()->enabled);
        } finally {
            unlink($resultPath);
        }
    }

    private function appendDisabledEvent(string $reason): void
    {
        ForwardingSwitchEvent::query()->create([
            'enabled' => false,
            'actor' => 'test:concurrency',
            'reason' => $reason,
            'created_at' => now('UTC'),
        ]);
    }

    private function waitForAdvisoryWait(int $backendPid): void
    {
        $last = null;

        for ($attempt = 0; $attempt < 120; $attempt++) {
            $last = DB::selectOne(
                'SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE pid = ?',
                [$backendPid],
            );

            if (
                strtolower((string) ($last->wait_event_type ?? '')) === 'lock'
                && str_contains(strtolower((string) ($last->wait_event ?? '')), 'advisory')
            ) {
                return;
            }

            usleep(25000);
        }

        self::fail(
            'Worker did not reach the advisory lock wait: '.json_encode($last, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{resource, resource} */
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

    private function reservableInvoice(
        string $assetKey = 'btc',
        string $networkKey = 'bitcoin',
        string $amount = '0.50000000',
    ): Invoice {
        $merchant = $this->createMerchant(['fee_percent' => '0']);

        return $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => $assetKey,
            'asset_key' => $assetKey,
            'network_key' => $networkKey,
            'received_conf_coin' => $amount,
            'fee_coin' => str_starts_with($assetKey, 'eth_usdt') ? '0.000000' : '0.00000000',
            'merchant_payout_coin' => $amount,
            'settlement_snapshot_locked_at' => now('UTC'),
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
    }
}
