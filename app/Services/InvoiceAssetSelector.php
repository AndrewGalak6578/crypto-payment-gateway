<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\InvoiceAddressContext;
use App\Jobs\MonitorInvoiceJob;
use App\Models\Invoice;
use App\Services\CoinBasedLogic\CoinRate;
use App\Services\PaymentAddresses\PaymentAddressAllocatorManager;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainManager;
use App\Support\Chains\ChainRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Fixes a public invoice to one payment asset exactly once.
 */
final class InvoiceAssetSelector
{
    public function __construct(
        private readonly CoinRate $rates,
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
        private readonly PaymentAddressAllocatorManager $allocators,
        private readonly AssetPolicyResolver $assetPolicies,
    ) {}

    public function select(Invoice $invoice, string $assetKey): Invoice
    {
        $shouldMonitor = false;
        $expired = false;

        $selected = DB::transaction(function () use ($invoice, $assetKey, &$shouldMonitor, &$expired): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'awaiting_asset') {
                if ($locked->asset_key !== null) {
                    return $locked;
                }

                throw new DomainException('Invoice is not waiting for asset selection.');
            }

            if ($locked->expires_at && now('UTC')->gt($locked->expires_at)) {
                $locked->status = 'expired';
                $locked->save();
                $expired = true;

                return $locked->fresh(['merchant']);
            }

            $assetKey = strtolower(trim($assetKey));
            if (! $this->assets->exists($assetKey)) {
                throw new DomainException('Unsupported payment asset.');
            }

            $this->assetPolicies->assertCanCreateInvoice($locked->merchant, $assetKey);

            $asset = $this->assets->get($assetKey);
            $networkKey = (string) $asset['network'];
            $amountUsd = round((float) $locked->expected_usd, 3);
            $rateUsd = $this->rates->usd($assetKey);
            $settlementScale = (int) ($asset['settlement_scale'] ?? 8);
            $amountCoin = round($amountUsd / max($rateUsd, 1e-8), $settlementScale);

            $context = new InvoiceAddressContext(
                publicId: (string) $locked->public_id,
                externalId: $locked->external_id,
                metadata: is_array($locked->metadata) ? $locked->metadata : [],
            );

            $allocator = $this->allocators->forNetwork($networkKey);
            $paymentAddress = $allocator->allocate($locked->merchant, $assetKey, $networkKey, $context);

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];

            if ($this->chains->family($networkKey) === 'evm') {
                $driver = app(ChainManager::class)->driverForNetwork($networkKey);

                if (method_exists($driver, 'blockNumber')) {
                    $metadata['evm']['monitor_from_block'] = $driver->blockNumber();
                }
            }

            $monitorTtlHours = (int) config('payments.monitor.ttl_hours', 24);

            $locked->forceFill([
                'status' => 'pending',
                'coin' => $assetKey,
                'asset_key' => $assetKey,
                'network_key' => $networkKey,
                'pay_address' => $paymentAddress->address,
                'amount_coin' => $amountCoin,
                'rate_usd' => $rateUsd,
                'monitor_until' => $locked->expires_at?->copy()->addHours($monitorTtlHours),
                'metadata' => $metadata,
            ])->save();

            $allocator->attachToInvoice($paymentAddress, $locked);
            $shouldMonitor = true;

            return $locked->fresh(['merchant']);
        });

        if ($expired) {
            throw new DomainException('Invoice has expired.');
        }

        if ($shouldMonitor && (bool) config('payments.monitor.enabled', true)) {
            MonitorInvoiceJob::dispatch($selected->id)->delay(now('UTC')->addSeconds(2));
        }

        return $selected;
    }
}
