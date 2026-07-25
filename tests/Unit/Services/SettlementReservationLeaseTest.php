<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantSettlementAttempt;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class SettlementReservationLeaseTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_active_lease_is_not_reaped_but_expired_reserved_lease_is_retry_safe(): void
    {
        [$invoice, $attempt] = $this->reserve();
        $manager = app(MerchantSettlementAttemptManager::class);

        self::assertSame(0, $manager->reapExpiredReservations());
        self::assertSame(MerchantSettlementAttempt::STATE_RESERVED, $attempt->fresh()->state);

        $attempt->forceFill(['lease_expires_at' => now('UTC')->subSecond()])->save();
        self::assertSame(1, $manager->reapExpiredReservations());
        self::assertSame(MerchantSettlementAttempt::STATE_FAILED, $attempt->fresh()->state);
        self::assertTrue($attempt->fresh()->retry_safe);
        self::assertSame(Invoice::FORWARD_STATUS_FAILED, $invoice->fresh()->forward_status);
    }

    public function test_broadcasting_attempt_is_never_reaped_as_retry_safe(): void
    {
        [, $attempt, $ownerToken] = $this->reserve();
        $manager = app(MerchantSettlementAttemptManager::class);
        $manager->markBroadcasting(
            attemptId: $attempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
            ownerToken: $ownerToken,
        );

        $attempt->forceFill(['lease_expires_at' => now('UTC')->subDay()])->save();

        self::assertSame(0, $manager->reapExpiredReservations());
        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTING, $attempt->fresh()->state);
        self::assertFalse($attempt->fresh()->retry_safe);
    }

    public function test_broadcasting_boundary_cannot_be_reentered(): void
    {
        [, $attempt, $ownerToken] = $this->reserve();
        $manager = app(MerchantSettlementAttemptManager::class);
        $manager->markBroadcasting(
            attemptId: $attempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
            ownerToken: $ownerToken,
        );

        try {
            $manager->markBroadcasting(
                attemptId: $attempt->id,
                sourceReference: 'rpc-wallet:bitcoin',
                ownerToken: $ownerToken,
            );
            self::fail('A broadcasting settlement attempt reentered the send boundary.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already crossed', $exception->getMessage());
        }

        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTING, $attempt->fresh()->state);
        self::assertFalse($attempt->fresh()->retry_safe);
    }

    public function test_expired_pre_broadcast_attempt_releases_unused_evm_nonce_for_retry(): void
    {
        [$invoice, $attempt, $ownerToken] = $this->reserve();
        $manager = app(MerchantSettlementAttemptManager::class);
        $sourceAddress = '0x2222222222222222222222222222222222222222';

        $manager->recordPreparationContext(
            attemptId: $attempt->id,
            sourceAddress: $sourceAddress,
            nonce: 7,
            chainId: '31337',
            ownerToken: $ownerToken,
        );
        $attempt->forceFill(['lease_expires_at' => now('UTC')->subSecond()])->save();
        self::assertSame(1, $manager->reapExpiredReservations());

        $replacementOwner = (string) Str::uuid();
        $replacement = $manager->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'evm',
            transferType: MerchantSettlementAttempt::TRANSFER_EVM_NATIVE,
            destinationAddress: '0x1111111111111111111111111111111111111111',
            ownerToken: $replacementOwner,
        );
        self::assertNotNull($replacement);

        $manager->recordPreparationContext(
            attemptId: $replacement->id,
            sourceAddress: $sourceAddress,
            nonce: 7,
            chainId: '31337',
            ownerToken: $replacementOwner,
        );
        $manager->markBroadcasting(
            attemptId: $replacement->id,
            sourceAddress: $sourceAddress,
            nonce: 7,
            ownerToken: $replacementOwner,
        );

        self::assertSame(MerchantSettlementAttempt::STATE_BROADCASTING, $replacement->fresh()->state);
    }

    public function test_public_reservation_paths_share_blocking_attempt_behavior(): void
    {
        [$invoice, $attempt] = $this->reserve();
        $invoice = $invoice->fresh();
        self::assertSame($attempt->attempt_uuid, $invoice->forward_attempt_uuid);

        $manager = app(MerchantSettlementAttemptManager::class);

        $invoice->forceFill([
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
            'forward_attempt_uuid' => null,
        ])->save();

        $duplicate = $manager->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'utxo',
            transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
            destinationAddress: 'bcrt1qleasedestination',
            ownerToken: (string) Str::uuid(),
        );

        self::assertNull($duplicate);
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
        self::assertSame($attempt->attempt_uuid, $invoice->fresh()->forward_attempt_uuid);

        $invoice->forceFill([
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
            'forward_attempt_uuid' => null,
        ])->save();

        $lockedDuplicate = DB::transaction(function () use ($invoice, $manager): ?MerchantSettlementAttempt {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($lockedInvoice->merchant_id);
            $lockedInvoice->setRelation('merchant', $lockedMerchant);

            return $manager->reserveLocked(
                invoice: $lockedInvoice,
                chainFamily: 'utxo',
                transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
                destinationAddress: 'bcrt1qleasedestination',
                ownerToken: (string) Str::uuid(),
            );
        });

        self::assertNull($lockedDuplicate);
        self::assertSame(Invoice::FORWARD_STATUS_PROCESSING, $invoice->fresh()->forward_status);
        self::assertSame($attempt->attempt_uuid, $invoice->fresh()->forward_attempt_uuid);
        self::assertSame(
            1,
            MerchantSettlementAttempt::query()->where('invoice_id', $invoice->id)->count(),
        );
    }

    /**
     * @return array{Invoice, MerchantSettlementAttempt, string}
     */
    private function reserve(): array
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'received_conf_coin' => '0.50000000',
            'fee_coin' => '0.01000000',
            'merchant_payout_coin' => '0.49000000',
            'settlement_snapshot_locked_at' => now('UTC'),
            'forward_status' => Invoice::FORWARD_STATUS_NONE,
        ]);
        $ownerToken = (string) Str::uuid();
        $attempt = app(MerchantSettlementAttemptManager::class)->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'utxo',
            transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
            destinationAddress: 'bcrt1qleasedestination',
            ownerToken: $ownerToken,
        );
        self::assertNotNull($attempt);

        return [$invoice, $attempt, $ownerToken];
    }
}
