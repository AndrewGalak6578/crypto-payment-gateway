<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\MerchantSettlementAttempt;
use App\Services\Settlement\MerchantSettlementAttemptManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class SettlementAuditRetentionTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_invoice_with_completed_settlement_audit_cannot_be_deleted(): void
    {
        [$invoice] = $this->completedSettlement();

        $this->expectException(QueryException::class);
        $invoice->delete();
    }

    public function test_merchant_with_completed_settlement_audit_cannot_be_cascade_deleted(): void
    {
        [, $merchant] = $this->completedSettlement();

        $this->expectException(QueryException::class);
        $merchant->delete();
    }

    private function completedSettlement(): array
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
        $manager = app(MerchantSettlementAttemptManager::class);
        $attempt = $manager->reserve(
            invoiceId: $invoice->id,
            chainFamily: 'utxo',
            transferType: MerchantSettlementAttempt::TRANSFER_UTXO,
            destinationAddress: 'bcrt1qauditdestination',
            ownerToken: $ownerToken,
        );
        self::assertNotNull($attempt);
        $manager->markBroadcasting(
            attemptId: $attempt->id,
            sourceReference: 'rpc-wallet:bitcoin',
            ownerToken: $ownerToken,
        );
        $manager->markBroadcasted($attempt->id, 'audit-txid', '0.49000000');
        $manager->markConfirmed($attempt->id, ['operator_test_evidence' => true]);
        $manager->complete($attempt->id);

        return [$invoice, $merchant];
    }
}
