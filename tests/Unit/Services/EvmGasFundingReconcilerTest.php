<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\EvmGasFundingEvidenceProviderInterface;
use App\Data\EvmGasFundingReconciliationResult;
use App\Jobs\ForwardInvoiceJob;
use App\Models\EvmGasFunding;
use App\Models\Invoice;
use App\Services\Evm\EvmGasFundingReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class EvmGasFundingReconcilerTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_pending_and_ambiguous_funding_remain_blocking_without_continuation(): void
    {
        Queue::fake();
        [$invoice, $funding] = $this->funding();
        $provider = new FakeGasFundingEvidenceProvider(
            EvmGasFundingReconciliationResult::pending('pending', $funding->tx_hash),
        );
        $this->app->instance(EvmGasFundingEvidenceProviderInterface::class, $provider);

        app(EvmGasFundingReconciler::class)->reconcile($funding->id, true);

        self::assertSame(EvmGasFunding::STATE_BROADCASTED, $funding->fresh()->state);
        self::assertNotNull($funding->fresh()->next_reconciliation_at);
        Queue::assertNotPushed(ForwardInvoiceJob::class);

        $provider->result = EvmGasFundingReconciliationResult::inconclusive(
            'missing_or_ambiguous_evidence',
            $funding->tx_hash,
        );
        app(EvmGasFundingReconciler::class)->reconcile($funding->id, true);

        self::assertSame(EvmGasFunding::STATE_NEEDS_RECONCILIATION, $funding->fresh()->state);
        self::assertFalse($funding->fresh()->retry_safe);
        self::assertSame(Invoice::FORWARD_STATUS_NONE, $invoice->fresh()->forward_status);
        Queue::assertNotPushed(ForwardInvoiceJob::class);
    }

    public function test_repeated_confirmed_reconciliation_throttles_continuation_and_recovers_after_cooldown(): void
    {
        Queue::fake();
        config()->set('payment_addresses.evm.gas_topup.continuation_stale_seconds', 30);
        [$invoice, $funding] = $this->funding();
        $provider = new FakeGasFundingEvidenceProvider(
            EvmGasFundingReconciliationResult::confirmed((string) $funding->tx_hash, 3),
        );
        $this->app->instance(EvmGasFundingEvidenceProviderInterface::class, $provider);
        $reconciler = app(EvmGasFundingReconciler::class);

        DB::beginTransaction();
        $reconciler->reconcile($funding->id, true);

        self::assertSame(EvmGasFunding::STATE_CONFIRMED, $funding->fresh()->state);
        self::assertFalse($funding->fresh()->retry_safe);
        self::assertNotNull($funding->fresh()->continuation_dispatched_at);
        Queue::assertNotPushed(ForwardInvoiceJob::class);
        DB::commit();

        Queue::assertPushed(
            ForwardInvoiceJob::class,
            fn (ForwardInvoiceJob $job): bool => $job->invoiceId === $invoice->id,
        );
        $firstDispatchedAt = $funding->fresh()->continuation_dispatched_at;

        $reconciler->reconcile($funding->id, true);

        Queue::assertPushed(ForwardInvoiceJob::class, 1);
        self::assertTrue($firstDispatchedAt->equalTo($funding->fresh()->continuation_dispatched_at));

        $staleAt = now('UTC')->subSeconds(31);
        $funding->forceFill(['continuation_dispatched_at' => $staleAt])->save();
        Queue::getFacadeRoot()->releaseUniqueJobLocks();
        $reconciler->reconcile($funding->id, true);

        Queue::assertPushed(ForwardInvoiceJob::class, 2);
        self::assertTrue($funding->fresh()->continuation_dispatched_at->greaterThan($staleAt));
    }

    public function test_repeated_failed_safe_reconciliation_dispatches_one_recent_continuation(): void
    {
        Queue::fake();
        config()->set('payment_addresses.evm.gas_topup.continuation_stale_seconds', 300);
        [$invoice, $funding] = $this->funding('0x'.str_repeat('b', 64));
        $provider = new FakeGasFundingEvidenceProvider(
            EvmGasFundingReconciliationResult::failedSafe(
                (string) $funding->tx_hash,
                3,
                ['receipt_status' => '0x0'],
            ),
        );
        $this->app->instance(EvmGasFundingEvidenceProviderInterface::class, $provider);
        $reconciler = app(EvmGasFundingReconciler::class);

        $reconciler->reconcile($funding->id, true);
        $firstDispatchedAt = $funding->fresh()->continuation_dispatched_at;
        $reconciler->reconcile($funding->id, true);

        self::assertSame(EvmGasFunding::STATE_FAILED, $funding->fresh()->state);
        self::assertTrue($funding->fresh()->retry_safe);
        self::assertNotNull($firstDispatchedAt);
        self::assertTrue($firstDispatchedAt->equalTo($funding->fresh()->continuation_dispatched_at));
        Queue::assertPushed(
            ForwardInvoiceJob::class,
            fn (ForwardInvoiceJob $job): bool => $job->invoiceId === $invoice->id,
        );
        Queue::assertPushed(ForwardInvoiceJob::class, 1);
    }

    public function test_recovered_hash_is_persisted_before_confirmation(): void
    {
        Queue::fake();
        [, $funding] = $this->funding(null);
        $recoveredHash = '0x'.str_repeat('c', 64);
        $provider = new FakeGasFundingEvidenceProvider(
            EvmGasFundingReconciliationResult::confirmed($recoveredHash, 2),
        );
        $this->app->instance(EvmGasFundingEvidenceProviderInterface::class, $provider);

        app(EvmGasFundingReconciler::class)->reconcile($funding->id, true);

        self::assertSame($recoveredHash, $funding->fresh()->tx_hash);
        self::assertSame(EvmGasFunding::STATE_CONFIRMED, $funding->fresh()->state);
        self::assertSame(1, $funding->fresh()->reconciliation_attempts);
    }

    /** @return array{Invoice, EvmGasFunding} */
    private function funding(?string $txHash = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        $merchant = $this->createMerchant(['fee_percent' => '0']);
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'coin' => 'eth_usdt_local',
            'asset_key' => 'eth_usdt_local',
            'network_key' => 'evm_local',
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        $funding = EvmGasFunding::query()->create([
            'funding_uuid' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'network_key' => 'evm_local',
            'asset_key' => 'eth_usdt_local',
            'source_address' => '0x1111111111111111111111111111111111111111',
            'target_address' => '0x2222222222222222222222222222222222222222',
            'amount_native_wei' => '250000000000000',
            'tx_hash' => $txHash,
            'status' => 'submitted',
            'state' => EvmGasFunding::STATE_BROADCASTED,
            'retry_safe' => false,
            'chain_id' => '31337',
            'nonce' => (string) EvmGasFunding::query()->count(),
            'required_confirmations' => 1,
            'broadcast_block_number' => 10,
            'transaction_fingerprint' => str_repeat('d', 64),
            'reserved_at' => now('UTC'),
            'broadcasting_at' => now('UTC'),
            'broadcasted_at' => now('UTC'),
            'next_reconciliation_at' => now('UTC'),
            'meta' => [],
        ]);

        return [$invoice, $funding];
    }
}

final class FakeGasFundingEvidenceProvider implements EvmGasFundingEvidenceProviderInterface
{
    public function __construct(public EvmGasFundingReconciliationResult $result) {}

    public function inspect(EvmGasFunding $funding): EvmGasFundingReconciliationResult
    {
        return $this->result;
    }
}
