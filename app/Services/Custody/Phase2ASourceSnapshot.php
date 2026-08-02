<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Data\Phase2ASourceSnapshotData;
use App\Exceptions\CustodyAccountingException;
use App\Models\CustodyJournalSourceLink;
use App\Models\MerchantSettlementEntry;
use App\Services\Settlement\SettlementDecimal;
use App\Support\Assets\AssetRegistry;
use Carbon\CarbonImmutable;

final readonly class Phase2ASourceSnapshot
{
    public function __construct(
        private AssetRegistry $assets,
        private SettlementDecimal $decimal,
        private CustodyCanonicalPayload $canonical,
    ) {}

    public function build(MerchantSettlementEntry $source): Phase2ASourceSnapshotData
    {
        $source->loadMissing('invoice');
        $invoice = $source->invoice;

        if (
            $invoice === null
            || $source->occurred_at === null
            || $source->type !== MerchantSettlementEntry::TYPE_INTERNAL_CREDIT
            || $source->status !== MerchantSettlementEntry::STATUS_COMPLETED
            || $source->settlement_attempt_id !== null
            || $source->destination_wallet !== null
            || $source->txid !== null
            || $source->error_message !== null
            || $source->network_key === null
        ) {
            throw new CustodyAccountingException('Phase 2A source does not have the exact completed shape.');
        }

        $assetKey = $source->asset_key;
        $assetScale = $this->assets->settlementScale($assetKey);
        if (
            $this->assets->network($assetKey) !== $source->network_key
            || (int) $source->merchant_id !== (int) $invoice->merchant_id
        ) {
            throw new CustodyAccountingException('Phase 2A source asset, network, or merchant is invalid.');
        }

        $rawAmount = $source->getRawOriginal('amount_coin');
        $rawFeeCoin = $source->getRawOriginal('fee_coin');
        $rawAmountUsd = $source->getRawOriginal('amount_usd');
        $amount = $this->decimal->formatExact((string) $rawAmount, $assetKey);
        $feeCoin = $rawFeeCoin === null
            ? null
            : $this->decimal->formatExact((string) $rawFeeCoin, $assetKey);
        $amountUsd = $rawAmountUsd === null
            ? null
            : $this->decimal->usdExact((string) $rawAmountUsd);
        $metadata = [
            'invoice_public_id' => (string) $invoice->public_id,
            'reason' => 'internal_balance_only',
        ];

        if (
            $source->idempotency_key !== "invoice:{$invoice->id}:internal-credit"
            || $source->metadata != $metadata
        ) {
            throw new CustodyAccountingException('Phase 2A source key or metadata is not exact.');
        }

        $payload = [
            'amount_coin' => $amount,
            'amount_usd' => $amountUsd,
            'asset_key' => $assetKey,
            'asset_scale' => $assetScale,
            'destination_wallet' => null,
            'error_message' => null,
            'fee_coin' => $feeCoin,
            'id' => (int) $source->id,
            'idempotency_key' => $source->idempotency_key,
            'invoice_id' => (int) $source->invoice_id,
            'merchant_id' => (int) $source->merchant_id,
            'metadata' => $metadata,
            'network_key' => $source->network_key,
            'occurred_at' => CarbonImmutable::instance($source->occurred_at)
                ->utc()
                ->format('Y-m-d\TH:i:s.u\Z'),
            'settlement_attempt_id' => null,
            'source_kind' => CustodyJournalSourceLink::SOURCE_KIND,
            'source_version' => CustodyJournalSourceLink::SOURCE_VERSION,
            'status' => MerchantSettlementEntry::STATUS_COMPLETED,
            'txid' => null,
            'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
        ];
        $canonicalText = $this->canonical->json($payload);

        return new Phase2ASourceSnapshotData(
            payload: $payload,
            canonicalText: $canonicalText,
            hash: hash('sha256', $canonicalText),
            assetScale: $assetScale,
            amount: $amount,
            amountAtomic: $this->decimal->atomicExact($amount, $assetKey),
        );
    }
}
