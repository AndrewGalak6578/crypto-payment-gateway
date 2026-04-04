<?php
declare(strict_types=1);

namespace App\Services;

use App\Data\InvoiceAddressContext;
use App\Jobs\MonitorInvoiceJob;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\PaymentAddresses\PaymentAddressAllocatorManager;
use App\Support\Assets\AssetRegistry;
use App\Support\Coin;
use Illuminate\Support\Str;

/**
 * Creates invoices with rate snapshot, generated payment address, and monitoring schedule.
 */
class InvoiceCreator
{
    public function __construct(
        private CoinRate $rates,
        private readonly AssetRegistry $assets,
        private readonly PaymentAddressAllocatorManager $allocators,
    ) {}

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
        $assetKey = Coin::normalize($data['coin'] ?? 'dash');
        $asset = $this->assets->get($assetKey);
        $networkKey = (string) $asset['network'];

        $externalId = $data['external_id'] ?? null;

        if ($externalId) {
            $existing = Invoice::where('merchant_id', $merchant->id)
                ->where('external_id', $externalId)
                ->first();

            if ($existing) return $existing;
        }

        $amountUsd = round((float)$data['amount_usd'], 3);
        $rateUsd = $this->rates->usd($assetKey);

        $settlementScale = (int) ($asset['settlement_scale'] ?? 8);
        $amountCoin = round($amountUsd / max($rateUsd, 1e-8), $settlementScale);

        $expiresMin = (int)($data['expires_minutes'] ?? config('payments.invoice.ttl_minutes', 60));
        $deadline = now('UTC')->addMinutes($expiresMin);
        $monitorTtlHours = (int)config('payments.monitor.ttl_hours', 24);

        $publicId = Str::lower(Str::random(16));

        $context = new InvoiceAddressContext(
            publicId: $publicId,
            externalId: $externalId,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );

        $allocator = $this->allocators->forNetwork($networkKey);
        $paymentAddress = $allocator->allocate($merchant, $assetKey, $networkKey, $context);

        $inv = Invoice::create([
            'merchant_id' => $merchant->id,
            'public_id' => $publicId,
            'external_id' => $externalId,
            'status' => 'pending',
            'coin' => $assetKey,
            'asset_key' => $assetKey,
            'network_key' => $networkKey,
            'pay_address' => $paymentAddress->address,
            'amount_coin' => $amountCoin,
            'expected_usd' => $amountUsd,
            'rate_usd' => $rateUsd,
            'expires_at' => $deadline,
            'monitor_until' => $deadline->copy()->addHours($monitorTtlHours),
            'metadata' => $data['metadata'] ?? null,
        ])->fresh();

        $allocator->attachToInvoice($paymentAddress, $inv);

        if ((bool)config('payments.monitor.enabled', true)) {
            MonitorInvoiceJob::dispatch($inv->id)->delay(now('UTC')->addSeconds(2));
        }

        return $inv;
    }
}
