<?php
declare(strict_types=1);

namespace App\Services;

use App\Jobs\MonitorInvoiceJob;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Support\Coin;
use Illuminate\Support\Str;

/**
 * Creates invoices with rate snapshot, generated payment address, and monitoring schedule.
 */
class InvoiceCreator
{
    public function __construct(private CoinRate $rates) {}

    /**
     * Creates or returns existing invoice for merchant/external_id pair.
     *
     * @param Merchant $merchant Authenticated merchant owner.
     * @param array{
     *     amount_usd: float|int|string,
     *     coin?: string,
     *     external_id?: string|null,
     *     expires_minutes?: int|string,
     *     metadata?: array<string, mixed>|null
     * } $data
     * @return Invoice
     */
    public function create(Merchant $merchant, array $data): Invoice
    {
        $coin = Coin::normalize($data['coin'] ?? 'dash');
        $externalId = $data['external_id'] ?? null;

        if ($externalId) {
            $existing = Invoice::where('merchant_id', $merchant->id)
                ->where('external_id', $externalId)
                ->first();

            if ($existing) return $existing;
        }

        $amountUsd = round((float)$data['amount_usd'], 3);

        $rateUsd = $this->rates->usd($coin);

        $decimals = $coin === 'dash' ? 3 : 8;
        $amountCoin = round($amountUsd / max($rateUsd, 1e-8), $decimals);

        $expiresMin = (int)($data['expires_minutes'] ?? config('payments.invoice.ttl_minutes', 60));
        $deadline = now('UTC')->addMinutes($expiresMin);

        $monitorTtlHours = (int)config('payments.monitor.ttl_hours', 24);

        $publicId = Str::lower(Str::random(16));

        $rpc = Coin::rpc($coin);
        $address = $rpc->getNewAddress("inv:{$publicId}");

        $inv = Invoice::create([
            'merchant_id' => $merchant->id,
            'public_id' => $publicId,
            'external_id' => $externalId,
            'status' => 'pending',
            'coin' => $coin,
            'pay_address' => $address,
            'amount_coin' => $amountCoin,
            'expected_usd' => $amountUsd,
            'rate_usd' => $rateUsd,
            'expires_at' => $deadline,
            'monitor_until' => $deadline->copy()->addHours($monitorTtlHours),
            'metadata' => $data['metadata'] ?? null,
        ])->fresh();

        if ((bool)config('payments.monitor.enabled', true)) {
            MonitorInvoiceJob::dispatch($inv->id)->delay(now('UTC')->addSeconds(2));
        }

        return $inv;
    }
}
