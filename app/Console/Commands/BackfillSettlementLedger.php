<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\MerchantSettlementEntry;
use Illuminate\Console\Command;

class BackfillSettlementLedger extends Command
{
    protected $signature = 'settlements:backfill-ledger
        {--merchant-id= : Limit backfill to one merchant}
        {--dry-run : Show what would be created without writing}';

    protected $description = 'Backfill merchant settlement ledger entries from existing invoice forwarding summaries';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $merchantId = $this->option('merchant-id');
        $created = 0;
        $skipped = 0;

        Invoice::query()
            ->when($merchantId, fn ($query) => $query->where('merchant_id', (int) $merchantId))
            ->where('status', 'paid')
            ->where(function ($query): void {
                $query
                    ->whereIn('forward_status', ['done', 'partial', 'failed', 'processing'])
                    ->orWhereNotNull('forward_txids')
                    ->orWhereNotNull('forwarded_coin')
                    ->orWhereNotNull('merchant_payout_coin');
            })
            ->orderBy('id')
            ->chunkById(500, function ($invoices) use (&$created, &$skipped, $dryRun): void {
                foreach ($invoices as $invoice) {
                    /** @var Invoice $invoice */
                    $payload = $this->entryPayload($invoice);

                    if ($payload === null) {
                        $skipped++;
                        continue;
                    }

                    if (MerchantSettlementEntry::query()->where('idempotency_key', $payload['idempotency_key'])->exists()) {
                        $skipped++;
                        continue;
                    }

                    $this->line(sprintf(
                        '%s invoice #%d %s %s %s',
                        $dryRun ? 'Would backfill' : 'Backfilling',
                        $invoice->id,
                        $payload['type'],
                        $payload['status'],
                        $payload['amount_coin'],
                    ));

                    if (!$dryRun) {
                        MerchantSettlementEntry::query()->create($payload);
                    }

                    $created++;
                }
            });

        $this->info("Settlement ledger backfill complete. Created: {$created}. Skipped: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryPayload(Invoice $invoice): ?array
    {
        $txids = collect($invoice->forward_txids ?? [])
            ->filter(fn ($txid) => is_string($txid) && trim($txid) !== '')
            ->values()
            ->all();

        $forwardStatus = (string) ($invoice->forward_status ?? 'none');
        $forwardedCoin = $this->decimalString($invoice->forwarded_coin);
        $payoutCoin = $this->decimalString($invoice->merchant_payout_coin);
        $fallbackAmount = $this->isPositiveDecimal($forwardedCoin) ? $forwardedCoin : $payoutCoin;

        if ($txids !== [] || $forwardStatus === 'partial') {
            return $this->basePayload($invoice, [
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => $forwardStatus === 'failed'
                    ? MerchantSettlementEntry::STATUS_FAILED
                    : MerchantSettlementEntry::STATUS_COMPLETED,
                'amount_coin' => $fallbackAmount,
                'txid' => count($txids) === 1 ? $txids[0] : null,
                'idempotency_key' => "invoice:{$invoice->id}:backfill:forward",
                'metadata' => [
                    'backfilled' => true,
                    'source' => 'invoices.forward_txids',
                    'forward_status' => $forwardStatus,
                    'forward_txids' => $txids,
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => $invoice->last_forwarded_at ?? $invoice->updated_at ?? $invoice->created_at,
            ]);
        }

        if ($forwardStatus === 'failed') {
            return $this->basePayload($invoice, [
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => MerchantSettlementEntry::STATUS_FAILED,
                'amount_coin' => $this->decimalString($invoice->forwarding_coin ?? $fallbackAmount),
                'txid' => null,
                'idempotency_key' => "invoice:{$invoice->id}:backfill:forward-failed",
                'error_message' => 'Backfilled from invoice forward_status=failed.',
                'metadata' => [
                    'backfilled' => true,
                    'source' => 'invoices.forward_status',
                    'forward_status' => $forwardStatus,
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => $invoice->forwarding_started_at ?? $invoice->updated_at ?? $invoice->created_at,
            ]);
        }

        if ($forwardStatus === 'processing') {
            return $this->basePayload($invoice, [
                'type' => MerchantSettlementEntry::TYPE_FORWARD_SENT,
                'status' => MerchantSettlementEntry::STATUS_PENDING,
                'amount_coin' => $this->decimalString($invoice->forwarding_coin ?? $fallbackAmount),
                'txid' => null,
                'idempotency_key' => "invoice:{$invoice->id}:backfill:forward-pending",
                'metadata' => [
                    'backfilled' => true,
                    'source' => 'invoices.forward_status',
                    'forward_status' => $forwardStatus,
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => $invoice->forwarding_started_at ?? $invoice->updated_at ?? $invoice->created_at,
            ]);
        }

        if ($forwardStatus === 'done' && $this->isPositiveDecimal($payoutCoin)) {
            return $this->basePayload($invoice, [
                'type' => MerchantSettlementEntry::TYPE_INTERNAL_CREDIT,
                'status' => MerchantSettlementEntry::STATUS_COMPLETED,
                'amount_coin' => $payoutCoin,
                'txid' => null,
                'idempotency_key' => "invoice:{$invoice->id}:backfill:internal-credit",
                'metadata' => [
                    'backfilled' => true,
                    'source' => 'invoices.forward_status',
                    'reason' => 'no_forward_txids_present',
                    'invoice_public_id' => $invoice->public_id,
                ],
                'occurred_at' => $invoice->last_forwarded_at ?? $invoice->updated_at ?? $invoice->created_at,
            ]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function basePayload(Invoice $invoice, array $overrides): array
    {
        $amountCoin = $this->decimalString($overrides['amount_coin'] ?? 0);
        $amount = (float) $amountCoin;
        $rate = (float) ($invoice->rate_usd ?? 0);

        $payload = array_merge([
            'merchant_id' => $invoice->merchant_id,
            'invoice_id' => $invoice->id,
            'asset_key' => $invoice->resolvedAssetKey(),
            'network_key' => $invoice->resolvedNetworkKey(),
            'fee_coin' => $invoice->fee_coin,
            'amount_usd' => $rate > 0 ? round($amount * $rate, 2) : $invoice->merchant_payout_usd,
            'destination_wallet' => null,
            'error_message' => null,
        ], $overrides);

        $payload['amount_coin'] = $amountCoin;

        return $payload;
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_string($value) && ! str_contains(strtolower($value), 'e')) {
            return $this->trimDecimal($value);
        }

        return $this->trimDecimal(number_format((float) $value, 18, '.', ''));
    }

    private function trimDecimal(string $value): string
    {
        $value = trim($value);

        if (! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '-0' || $value === '' ? '0' : $value;
    }

    private function isPositiveDecimal(string $value): bool
    {
        return (float) $value > 0;
    }
}
