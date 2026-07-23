<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\SettlementAttemptEvidenceProviderInterface;
use App\Data\SettlementReconciliationResult;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\ReconcileSettlementAttemptJob;
use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Models\MerchantSettlementEntry;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use App\Services\Settlement\SettlementAttemptReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class SettlementAttemptReconcilerTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_pending_and_inconclusive_evidence_never_create_completed_accounting(): void
    {
        [$invoice, $attempt] = $this->broadcastedAttempt();
        $provider = new FakeSettlementEvidenceProvider(
            SettlementReconciliationResult::pending('transaction_pending', $attempt->txid),
        );
        $this->app->instance(SettlementAttemptEvidenceProviderInterface::class, $provider);

        app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);

        $pendingAttempt = $attempt->fresh();
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTED, $pendingAttempt->state);
        self::assertSame(1, $pendingAttempt->reconciliation_attempts);
        self::assertNotNull($pendingAttempt->last_reconciled_at);
        self::assertNotNull($pendingAttempt->next_reconciliation_at);
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);

        $provider->result = SettlementReconciliationResult::inconclusive(
            'transaction_dropped_or_rpc_inconclusive',
            $attempt->txid,
        );
        app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);

        self::assertSame(MerchantSettlementAttempt::STATE_NEEDS_RECONCILIATION, $attempt->fresh()->state);
        self::assertSame(Invoice::FORWARD_STATUS_NEEDS_RECONCILIATION, $invoice->fresh()->forward_status);
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);
    }

    public function test_broadcasted_attempt_cannot_complete_without_confirmation(): void
    {
        [$invoice, $attempt] = $this->broadcastedAttempt();

        try {
            app(MerchantSettlementAttemptManager::class)->complete($attempt->id);
            self::fail('A broadcasted settlement attempt was completed without chain confirmation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('expected [confirmed], got [broadcasted]', $exception->getMessage());
        }

        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTED, $attempt->fresh()->state);
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);
    }

    public function test_redelivered_job_during_live_reconciliation_lease_keeps_a_successor_scheduled(): void
    {
        Queue::fake();
        config()->set('queue.default', 'database');

        [, $attempt] = $this->broadcastedAttempt();
        $attempt->forceFill([
            'next_reconciliation_at' => now('UTC')->addMinutes(2),
            'reconciliation_owner_token' => (string) Str::uuid(),
            'reconciliation_lease_expires_at' => now('UTC')->addMinute(),
        ])->save();

        (new ReconcileSettlementAttemptJob($attempt->id))->handle(
            app(SettlementAttemptReconciler::class),
        );

        Queue::assertPushed(
            ReconcileSettlementAttemptJob::class,
            fn (ReconcileSettlementAttemptJob $job): bool => $job->attemptId === $attempt->id,
        );
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTED, $attempt->fresh()->state);
    }

    public function test_reconciliation_recovers_txid_and_completes_original_attempt_exactly_once(): void
    {
        [$invoice, $attempt, $ownerToken] = $this->reservedAttempt();
        app(MerchantSettlementAttemptManager::class)->markBroadcasting(
            attemptId: $attempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
            ownerToken: $ownerToken,
        );

        $provider = new FakeSettlementEvidenceProvider(
            SettlementReconciliationResult::confirmed(
                'recovered_txid',
                2,
                ['matched_broadcast_reference' => true],
            ),
        );
        $this->app->instance(SettlementAttemptEvidenceProviderInterface::class, $provider);

        $reconciler = app(SettlementAttemptReconciler::class);
        $reconciler->reconcile($attempt->id, true);
        $reconciler->reconcile($attempt->id, true);

        self::assertSame(MerchantSettlementAttempt::STATE_COMPLETED, $attempt->fresh()->state);
        self::assertSame('recovered_txid', $attempt->fresh()->txid);
        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
        self::assertSame(1, MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, $provider->calls);
    }

    public function test_confirmed_failed_evm_receipt_is_retry_safe_but_inconclusive_evidence_is_not(): void
    {
        [$invoice, $attempt] = $this->broadcastedAttempt('eth_local', 'evm_local', 'evm', MerchantSettlementAttempt::TRANSFER_EVM_NATIVE);
        $provider = new FakeSettlementEvidenceProvider(
            SettlementReconciliationResult::failedSafe(
                (string) $attempt->txid,
                3,
                ['receipt_status' => '0x0', 'identity_verified' => true],
            ),
        );
        $this->app->instance(SettlementAttemptEvidenceProviderInterface::class, $provider);

        app(SettlementAttemptReconciler::class)->reconcile($attempt->id, true);

        self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $attempt->fresh()->state);
        self::assertTrue($attempt->fresh()->retry_safe);
        self::assertSame(Invoice::FORWARD_STATUS_FAILED, $invoice->fresh()->forward_status);
        self::assertTrue($invoice->fresh()->hasRetryableForwardStatus());
        self::assertDatabaseMissing('merchant_settlement_entries', ['invoice_id' => $invoice->id]);

        $replacement = app(MerchantSettlementAttemptManager::class)->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'evm',
            transferType: MerchantSettlementAttempt::TRANSFER_EVM_NATIVE,
            destinationAddress: '0x1111111111111111111111111111111111111111',
        );
        self::assertNotNull($replacement);
        self::assertNotSame($attempt->id, $replacement->id);
    }

    public function test_settlement_commit_persists_one_recoverable_forwarded_delivery(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', true);
        [$invoice, $attempt] = $this->broadcastedAttempt();
        $provider = new FakeSettlementEvidenceProvider(
            SettlementReconciliationResult::confirmed((string) $attempt->txid, 2),
        );
        $this->app->instance(SettlementAttemptEvidenceProviderInterface::class, $provider);

        $reconciler = app(SettlementAttemptReconciler::class);
        $reconciler->reconcile($attempt->id, true);
        $reconciler->reconcile($attempt->id, true);

        self::assertSame(1, \App\Models\WebhookDelivery::query()
            ->where('invoice_id', $invoice->id)
            ->where('event', 'invoice.forwarded')
            ->count());

        Queue::fake();
        self::assertSame(0, Artisan::call('webhooks:dispatch-pending', ['--limit' => 10]));
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_each_confirmed_partial_attempt_has_its_own_idempotent_forwarded_delivery(): void
    {
        Queue::fake();
        config()->set('webhooks.enabled', true);
        [$invoice, $firstAttempt, $firstOwner] = $this->reservedAttempt();
        $manager = app(MerchantSettlementAttemptManager::class);

        $manager->markBroadcasting(
            attemptId: $firstAttempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
            ownerToken: $firstOwner,
        );
        $manager->markBroadcasted(
            attemptId: $firstAttempt->id,
            txid: 'partial_txid',
            broadcastAmount: '0.200000000000000000',
        );
        $manager->markConfirmed($firstAttempt->id, ['confirmations' => 2]);
        $manager->complete($firstAttempt->id);

        self::assertSame(Invoice::FORWARD_STATUS_PARTIAL, $invoice->fresh()->forward_status);

        $secondOwner = (string) Str::uuid();
        $secondAttempt = $manager->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'utxo',
            transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
            destinationAddress: 'bcrt1qreconciliationdestination',
            ownerToken: $secondOwner,
        );
        self::assertNotNull($secondAttempt);
        $manager->markBroadcasting(
            attemptId: $secondAttempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
            ownerToken: $secondOwner,
        );
        $manager->markBroadcasted(
            attemptId: $secondAttempt->id,
            txid: 'final_txid',
            broadcastAmount: (string) $secondAttempt->amount_coin,
        );
        $manager->markConfirmed($secondAttempt->id, ['confirmations' => 2]);
        $manager->complete($secondAttempt->id);
        $manager->complete($secondAttempt->id);

        self::assertSame(Invoice::FORWARD_STATUS_DONE, $invoice->fresh()->forward_status);
        $deliveries = \App\Models\WebhookDelivery::query()
            ->where('invoice_id', $invoice->id)
            ->where('event', 'invoice.forwarded')
            ->orderBy('id')
            ->get();
        self::assertCount(2, $deliveries);
        self::assertSame(
            "invoice:{$invoice->id}:event:invoice.forwarded:attempt:{$firstAttempt->attempt_uuid}",
            $deliveries[0]->idempotency_key,
        );
        self::assertSame(
            "invoice:{$invoice->id}:event:invoice.forwarded:attempt:{$secondAttempt->attempt_uuid}",
            $deliveries[1]->idempotency_key,
        );
    }

    /**
     * @return array{Invoice, MerchantSettlementAttempt}
     */
    private function broadcastedAttempt(
        string $assetKey = 'btc',
        string $networkKey = 'bitcoin',
        string $family = 'utxo',
        string $transferType = MerchantSettlementAttempt::TRANSFER_UTXO,
    ): array {
        [$invoice, $attempt, $ownerToken] = $this->reservedAttempt(
            $assetKey,
            $networkKey,
            $family,
            $transferType,
        );

        app(MerchantSettlementAttemptManager::class)->markBroadcasting(
            attemptId: $attempt->id,
            sourceAddress: $family === 'evm' ? '0x2222222222222222222222222222222222222222' : null,
            sourceReference: $family === 'utxo' ? "rpc-wallet:{$networkKey}" : 'vault:test',
            nonce: $family === 'evm' ? 7 : null,
            ownerToken: $ownerToken,
        );
        app(MerchantSettlementAttemptManager::class)->markBroadcasted(
            attemptId: $attempt->id,
            txid: $family === 'evm'
                ? '0x'.str_repeat('a', 64)
                : 'broadcasted_txid',
            broadcastAmount: (string) $attempt->amount_coin,
            nonce: $family === 'evm' ? 7 : null,
        );

        return [$invoice, $attempt->fresh()];
    }

    /**
     * @return array{Invoice, MerchantSettlementAttempt, string}
     */
    private function reservedAttempt(
        string $assetKey = 'btc',
        string $networkKey = 'bitcoin',
        string $family = 'utxo',
        string $transferType = MerchantSettlementAttempt::TRANSFER_UTXO,
    ): array {
        $merchant = $this->createMerchant(['fee_percent' => '2.0000']);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => $assetKey,
            'asset_key' => $assetKey,
            'network_key' => $networkKey,
            'received_conf_coin' => '0.500000000000000000',
            'fee_coin' => '0.010000000000000000',
            'merchant_payout_coin' => '0.490000000000000000',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        $ownerToken = (string) Str::uuid();
        $attempt = app(MerchantSettlementAttemptManager::class)->reserve(
            invoiceId: $invoice->id,
            chainFamily: $family,
            transferType: $transferType,
            destinationAddress: $family === 'evm'
                ? '0x1111111111111111111111111111111111111111'
                : 'bcrt1qreconciliationdestination',
            ownerToken: $ownerToken,
        );
        self::assertNotNull($attempt);

        return [$invoice, $attempt, $ownerToken];
    }
}

final class FakeSettlementEvidenceProvider implements SettlementAttemptEvidenceProviderInterface
{
    public int $calls = 0;

    public function __construct(public SettlementReconciliationResult $result) {}

    public function inspect(MerchantSettlementAttempt $attempt): SettlementReconciliationResult
    {
        $this->calls++;

        return $this->result;
    }
}
