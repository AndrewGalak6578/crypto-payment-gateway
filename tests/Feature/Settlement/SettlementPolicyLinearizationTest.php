<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\AssetPolicy;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSettlementAttempt;
use App\Models\MerchantSettlementPreference;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Models\SuperWallet;
use App\Services\MerchantSettlementPolicyUpdater;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use App\Services\SettlementPolicyResolver;
use Database\Seeders\MerchantAccessSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class SettlementPolicyLinearizationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_policy_update_racing_settlement_evaluation_cannot_change_reserved_snapshot(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertTrue(function_exists('pcntl_fork'), 'The settlement concurrency test requires ext-pcntl.');

        $this->seed(MerchantAccessSeeder::class);
        $merchant = Merchant::query()->create([
            'name' => 'Linearized Settlements',
            'status' => 'active',
            'fee_percent' => '1.00',
        ]);
        $actor = MerchantUser::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Settlement Owner',
            'email' => 'settlement-race@example.test',
            'password' => 'password123',
            'role_id' => Role::query()->where('slug', 'merchant.owner')->value('id'),
            'status' => 'active',
        ]);
        $invoice = Invoice::query()->create([
            'merchant_id' => $merchant->id,
            'public_id' => 'policy-race-'.Str::lower(Str::random(10)),
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'pay_address' => 'bcrt1qlinearizationdeposit',
            'amount_coin' => '0.01000000',
            'expected_usd' => '100.00',
            'rate_usd' => '10000.00000000',
            'received_conf_coin' => '0.01000000',
            'received_all_coin' => '0.01000000',
            'fee_coin' => '0.00010000',
            'merchant_payout_coin' => '0.00990000',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forwarded_coin' => '0',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
            'metadata' => [],
        ]);
        $wallet = SuperWallet::query()->create([
            'merchant_id' => $merchant->id,
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'wallet' => 'bcrt1qlinearizationdestination',
        ]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultPath = tempnam('/tmp', 'settlane-policy-race-');
        self::assertIsString($resultPath);

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'Unable to fork settlement policy race worker.');

        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            DB::reconnect();
            fread($childSocket, 1);
            file_put_contents($resultPath, "started\n");

            try {
                $childActor = MerchantUser::query()->findOrFail($actor->id);
                $request = Request::create(
                    '/api/merchant/settlement-policies/btc',
                    'PUT',
                    server: ['HTTP_X_REQUEST_ID' => 'settlement-policy-race-request'],
                );
                $request->attributes->set('merchant_user', $childActor);

                app(MerchantSettlementPolicyUpdater::class)->update(
                    request: $request,
                    actor: $childActor,
                    assetKey: 'btc',
                    expectedRevision: 0,
                    requested: [
                        'mode' => AssetPolicy::MODE_DISABLED,
                        'minimum_invoice_payout' => null,
                    ],
                );

                file_put_contents($resultPath, "completed\n", FILE_APPEND);
                fclose($childSocket);
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultPath, $exception::class.': '.$exception->getMessage(), FILE_APPEND);
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();
        $childWaited = false;

        try {
            $attempt = DB::transaction(function () use ($invoice, $merchant, $wallet, $parentSocket, $resultPath): MerchantSettlementAttempt {
                $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($merchant->id);
                $lockedInvoice->setRelation('merchant', $lockedMerchant);

                fwrite($parentSocket, '1');
                fflush($parentSocket);

                for ($wait = 0; $wait < 40; $wait++) {
                    usleep(25000);
                    if (str_contains((string) file_get_contents($resultPath), 'started')) {
                        break;
                    }
                }

                self::assertStringContainsString('started', (string) file_get_contents($resultPath));
                usleep(100000);
                self::assertTrue(
                    MerchantSettlementPreference::query()->where('merchant_id', $merchant->id)->doesntExist(),
                    'Preference update bypassed the locked merchant policy namespace.',
                );

                $decision = app(SettlementPolicyResolver::class)->resolveForInvoice($lockedInvoice, true);
                self::assertTrue($decision->forwardingAllowed);
                self::assertSame(AssetPolicy::MODE_IMMEDIATE, $decision->mode);

                return app(MerchantSettlementAttemptManager::class)->reserveLocked(
                    invoice: $lockedInvoice,
                    chainFamily: 'utxo',
                    transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
                    destinationAddress: $wallet->wallet,
                    metadata: ['policy_snapshot' => $decision->policySnapshot],
                    ownerToken: (string) Str::uuid(),
                ) ?? self::fail('Settlement attempt was not reserved.');
            });

            fclose($parentSocket);
            pcntl_waitpid($pid, $status);
            $childWaited = true;
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($resultPath));

            $preference = MerchantSettlementPreference::query()->sole();
            self::assertSame(AssetPolicy::MODE_DISABLED, $preference->requested_mode);
            self::assertSame(1, $preference->revision);
            self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
            self::assertSame(AssetPolicy::MODE_IMMEDIATE, $attempt->metadata['policy_snapshot']['effective']['mode']);
            self::assertNull($attempt->metadata['policy_snapshot']['requested']['mode']);
            self::assertSame('0.009900000000000000', (string) $attempt->amount_coin);
        } finally {
            if (! $childWaited) {
                pcntl_waitpid($pid, $status);
            }

            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (file_exists($resultPath)) {
                unlink($resultPath);
            }
        }
    }

    public function test_attempt_completion_uses_the_invoice_first_lock_order(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertTrue(function_exists('pcntl_fork'), 'The settlement concurrency test requires ext-pcntl.');

        $merchant = Merchant::query()->create([
            'name' => 'Attempt Lock Order',
            'status' => 'active',
            'fee_percent' => '1.00',
        ]);
        $invoice = Invoice::query()->create([
            'merchant_id' => $merchant->id,
            'public_id' => 'attempt-lock-'.Str::lower(Str::random(10)),
            'status' => 'paid',
            'coin' => 'btc',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'pay_address' => 'bcrt1qattemptlockdeposit',
            'amount_coin' => '0.01000000',
            'expected_usd' => '100.00',
            'rate_usd' => '10000.00000000',
            'received_conf_coin' => '0.01000000',
            'received_all_coin' => '0.01000000',
            'fee_coin' => '0.00010000',
            'merchant_payout_coin' => '0.00990000',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forwarded_coin' => '0',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
            'metadata' => [],
        ]);
        $ownerToken = (string) Str::uuid();
        $manager = app(MerchantSettlementAttemptManager::class);
        $attempt = $manager->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'utxo',
            transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
            destinationAddress: 'bcrt1qattemptlockdestination',
            ownerToken: $ownerToken,
        );
        self::assertNotNull($attempt);
        $manager->markBroadcasting(
            attemptId: $attempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
            ownerToken: $ownerToken,
        );
        $manager->markBroadcasted(
            attemptId: $attempt->id,
            txid: str_repeat('a', 64),
            broadcastAmount: '0.00990000',
        );
        $manager->markConfirmed($attempt->id, ['confirmations' => 6]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultPath = tempnam('/tmp', 'settlane-attempt-lock-');
        self::assertIsString($resultPath);

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'Unable to fork settlement completion worker.');

        if ($pid === 0) {
            fclose($parentSocket);
            DB::purge();
            DB::reconnect();
            fread($childSocket, 1);
            file_put_contents($resultPath, "started\n");

            try {
                app(MerchantSettlementAttemptManager::class)->complete($attempt->id);
                file_put_contents($resultPath, "completed\n", FILE_APPEND);
                fclose($childSocket);
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultPath, $exception::class.': '.$exception->getMessage(), FILE_APPEND);
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        DB::purge();
        DB::reconnect();
        $childWaited = false;

        try {
            DB::transaction(function () use ($invoice, $merchant, $attempt, $parentSocket, $resultPath): void {
                Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                Merchant::query()->lockForUpdate()->findOrFail($merchant->id);

                fwrite($parentSocket, '1');
                fflush($parentSocket);

                for ($wait = 0; $wait < 40; $wait++) {
                    usleep(25000);
                    if (str_contains((string) file_get_contents($resultPath), 'started')) {
                        break;
                    }
                }

                self::assertStringContainsString('started', (string) file_get_contents($resultPath));
                usleep(150000);

                $lockedAttempt = MerchantSettlementAttempt::query()
                    ->lockForUpdate()
                    ->findOrFail($attempt->id);
                self::assertSame(MerchantSettlementAttempt::STATE_CONFIRMED, $lockedAttempt->state);
            });

            fclose($parentSocket);
            pcntl_waitpid($pid, $status);
            $childWaited = true;
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($resultPath));
            self::assertStringContainsString('completed', (string) file_get_contents($resultPath));
            self::assertSame(MerchantSettlementAttempt::STATE_COMPLETED, $attempt->fresh()->state);
            self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
            self::assertSame(
                1,
                DB::table('merchant_settlement_entries')
                    ->where('settlement_attempt_id', $attempt->id)
                    ->count(),
            );
        } finally {
            if (! $childWaited) {
                pcntl_waitpid($pid, $status);
            }

            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (file_exists($resultPath)) {
                unlink($resultPath);
            }
        }
    }
}
