<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\SettlementPolicyDecision;
use App\Exceptions\CustodyAccountingException;
use App\Exceptions\CustodyIdempotencyConflictException;
use App\Models\AssetPolicy;
use App\Models\MerchantSettlementAttempt;
use App\Models\MerchantSettlementEntry;
use App\Services\Settlement\MerchantSettlementLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class MerchantSettlementLedgerTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_policy_hold_is_idempotent_and_enriches_an_existing_deferred_hold(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => 0.01,
        ]);
        $occurredAt = now('UTC')->subHour();

        MerchantSettlementEntry::query()->create([
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'type' => MerchantSettlementEntry::TYPE_FORWARD_HELD,
            'status' => MerchantSettlementEntry::STATUS_DEFERRED,
            'amount_coin' => 0.01,
            'fee_coin' => null,
            'amount_usd' => 100,
            'destination_wallet' => null,
            'txid' => null,
            'idempotency_key' => "invoice:{$invoice->id}:settlement-policy-hold",
            'error_message' => 'legacy_hold',
            'metadata' => ['invoice_public_id' => $invoice->public_id],
            'occurred_at' => $occurredAt,
        ]);

        $decision = new SettlementPolicyDecision(
            mode: AssetPolicy::MODE_THRESHOLD,
            reason: 'below_threshold',
            minSweepAmount: '0.10000000',
            maxGasCost: '0.00100000',
            forwardingAllowed: false,
            assetKey: 'btc',
            networkKey: 'bitcoin',
            remainingAmount: '0.01000000',
        );

        $ledger = app(MerchantSettlementLedger::class);
        $ledger->recordPolicyHold($invoice, $decision);
        $ledger->recordPolicyHold($invoice, $decision);

        $entry = MerchantSettlementEntry::query()
            ->where('invoice_id', $invoice->id)
            ->sole();

        self::assertSame(MerchantSettlementEntry::TYPE_FORWARD_HELD, $entry->type);
        self::assertSame(MerchantSettlementEntry::STATUS_DEFERRED, $entry->status);
        self::assertSame('below_threshold', $entry->error_message);
        self::assertSame(AssetPolicy::MODE_THRESHOLD, $entry->metadata['settlement_mode']);
        self::assertSame('below_threshold', $entry->metadata['reason']);
        self::assertSame('0.10000000', $entry->metadata['min_sweep_amount']);
        self::assertSame('0.00100000', $entry->metadata['max_gas_cost']);
        self::assertSame('0.01000000', $entry->metadata['remaining_amount']);
        self::assertFalse($entry->metadata['forwarding_allowed']);
        self::assertSame($occurredAt->toDateTimeString(), $entry->occurred_at->toDateTimeString());
    }

    public function test_policy_hold_does_not_overwrite_completed_or_differently_typed_entries(): void
    {
        $merchant = $this->createMerchant();
        $decision = new SettlementPolicyDecision(
            mode: AssetPolicy::MODE_MANUAL,
            reason: 'manual_settlement_required',
            minSweepAmount: null,
            maxGasCost: null,
            forwardingAllowed: false,
            assetKey: 'btc',
            networkKey: 'bitcoin',
            remainingAmount: '0.01000000',
        );

        $protectedEntries = [
            [MerchantSettlementEntry::TYPE_FORWARD_SENT, MerchantSettlementEntry::STATUS_COMPLETED],
            [MerchantSettlementEntry::TYPE_INTERNAL_CREDIT, MerchantSettlementEntry::STATUS_COMPLETED],
            [MerchantSettlementEntry::TYPE_FORWARD_HELD, MerchantSettlementEntry::STATUS_COMPLETED],
        ];

        foreach ($protectedEntries as $index => [$type, $status]) {
            $invoice = $this->createInvoice($merchant, [
                'status' => 'paid',
                'asset_key' => 'btc',
                'network_key' => 'bitcoin',
                'received_conf_coin' => 0.01,
            ]);
            $entry = MerchantSettlementEntry::query()->create([
                'merchant_id' => $merchant->id,
                'invoice_id' => $invoice->id,
                'asset_key' => 'btc',
                'network_key' => 'bitcoin',
                'type' => $type,
                'status' => $status,
                'amount_coin' => 0.01,
                'fee_coin' => null,
                'amount_usd' => 100,
                'destination_wallet' => null,
                'txid' => "protected-tx-{$index}",
                'idempotency_key' => "invoice:{$invoice->id}:settlement-policy-hold",
                'error_message' => null,
                'metadata' => ['protected' => true],
                'occurred_at' => now('UTC'),
            ]);

            $result = app(MerchantSettlementLedger::class)->recordPolicyHold($invoice, $decision);
            $fresh = $entry->fresh();

            self::assertSame($entry->id, $result->id);
            self::assertSame($type, $fresh->type);
            self::assertSame($status, $fresh->status);
            self::assertSame("protected-tx-{$index}", $fresh->txid);
            self::assertSame(['protected' => true], $fresh->metadata);
        }
    }

    public function test_internal_credit_is_immutable_and_idempotent(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => 0.5,
        ]);

        $ledger = app(MerchantSettlementLedger::class);
        $first = $ledger->recordInternalCredit(
            invoice: $invoice,
            amount: '0.49000000',
            feeCoin: '0.01000000',
            amountUsd: '4900.00',
            reason: 'internal_balance_only',
        );
        $second = $ledger->recordInternalCredit(
            invoice: $invoice,
            amount: '0.490000000000000000',
            feeCoin: '0.010000000000000000',
            amountUsd: '4900.000000',
            reason: 'internal_balance_only',
        );

        self::assertSame($first->id, $second->id);
        self::assertSame(1, MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->count());

        $fresh = $first->fresh();
        self::assertSame('0.490000000000000000', (string) $fresh->amount_coin);
        self::assertSame('0.010000000000000000', (string) $fresh->fee_coin);
        self::assertSame('internal_balance_only', $fresh->metadata['reason']);
    }

    public function test_internal_credit_replay_with_different_exact_payload_conflicts(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => 0.5,
        ]);
        $ledger = app(MerchantSettlementLedger::class);
        $ledger->recordInternalCredit(
            invoice: $invoice,
            amount: '0.49000000',
            feeCoin: '0.01000000',
            amountUsd: '4900.00',
            reason: 'internal_balance_only',
        );

        $this->expectException(CustodyIdempotencyConflictException::class);

        $ledger->recordInternalCredit(
            invoice: $invoice,
            amount: '0.25000000',
            feeCoin: '0.01000000',
            amountUsd: '2500.00',
            reason: 'internal_balance_only',
        );
    }

    public function test_internal_credit_rejects_excess_asset_fee_and_usd_precision_before_insert(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'received_conf_coin' => 0.5,
        ]);
        $ledger = app(MerchantSettlementLedger::class);
        $cases = [
            ['0.490000001', '0.01000000', '4900.00'],
            ['0.49000000', '0.010000001', '4900.00'],
            ['0.49000000', '0.01000000', '4900.001'],
        ];

        foreach ($cases as [$amount, $feeCoin, $amountUsd]) {
            try {
                $ledger->recordInternalCredit(
                    invoice: $invoice,
                    amount: $amount,
                    feeCoin: $feeCoin,
                    amountUsd: $amountUsd,
                    reason: 'internal_balance_only',
                );
                self::fail('Non-zero precision beyond the Phase 2A scale must fail before insert.');
            } catch (CustodyAccountingException $e) {
                self::assertStringContainsString('non-zero precision', $e->getMessage());
            }
        }

        self::assertSame(0, MerchantSettlementEntry::query()->count());
    }

    public function test_completed_forward_is_immutable_and_idempotent(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'fee_coin' => 0.01,
        ]);

        $ledger = app(MerchantSettlementLedger::class);
        $attempt = $this->settlementAttempt($invoice, 'attempt-immutable', 'tx-original', 'bcrt1qoriginaldestination');
        $first = $ledger->markForwardCompleted(
            invoice: $invoice,
            attempt: $attempt,
            amount: '0.49000000',
        );
        $second = $ledger->markForwardCompleted(
            invoice: $invoice,
            attempt: $attempt,
            amount: '0.49000000',
        );

        self::assertSame($first->id, $second->id);
        self::assertSame(1, MerchantSettlementEntry::query()->where('invoice_id', $invoice->id)->count());

        $fresh = $first->fresh();
        self::assertSame('0.490000000000000000', (string) $fresh->amount_coin);
        self::assertSame('tx-original', $fresh->txid);
        self::assertSame('bcrt1qoriginaldestination', $fresh->destination_wallet);
        self::assertSame($attempt->id, $fresh->settlement_attempt_id);
    }

    public function test_invoice_fee_is_recorded_once_across_multiple_completed_entries(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'paid',
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'fee_coin' => 0.01,
        ]);

        $ledger = app(MerchantSettlementLedger::class);
        $attempt = $this->settlementAttempt($invoice, 'attempt-partial-one', 'tx-partial-one', 'bcrt1qpartialdestination');
        $first = $ledger->markForwardCompleted(
            invoice: $invoice,
            attempt: $attempt,
            amount: '0.20000000',
        );
        $second = $ledger->recordInternalCredit(
            invoice: $invoice,
            amount: '0.29000000',
            feeCoin: '0.01000000',
            amountUsd: '2900.00',
            reason: 'internal_balance_only',
        );

        self::assertSame('0.010000000000000000', (string) $first->fee_coin);
        self::assertSame('0.000000000000000000', (string) $second->fee_coin);
        self::assertSame(
            '0.010000000000000000',
            (string) MerchantSettlementEntry::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', MerchantSettlementEntry::STATUS_COMPLETED)
                ->sum('fee_coin'),
        );
    }

    private function settlementAttempt(
        $invoice,
        string $uuid,
        string $txid,
        string $destination,
    ): MerchantSettlementAttempt {
        $attemptUuid = (string) Str::uuid();

        return MerchantSettlementAttempt::query()->create([
            'attempt_uuid' => $attemptUuid,
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'asset_key' => 'btc',
            'network_key' => 'bitcoin',
            'chain_family' => 'utxo',
            'transfer_type' => MerchantSettlementAttempt::TRANSFER_UTXO,
            'state' => MerchantSettlementAttempt::STATE_CONFIRMED,
            'retry_safe' => false,
            'amount_coin' => '0.49000000',
            'broadcast_amount_coin' => '0.49000000',
            'fee_coin_snapshot' => '0.01000000',
            'merchant_payout_coin_snapshot' => '0.49000000',
            'destination_address' => $destination,
            'txid' => $txid,
            'broadcast_reference' => "settlement:{$attemptUuid}",
            'required_confirmations' => 1,
            'lease_owner_token' => '11111111-1111-4111-8111-111111111111',
            'reserved_at' => now('UTC'),
        ]);
    }
}
