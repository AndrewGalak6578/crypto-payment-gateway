<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\Merchant;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;

/**
 * Resolves forwarding destination wallet with merchant override support.
 */
final class SuperWalletResolver
{
    public function __construct(private readonly AssetRegistry $assets) {}

    /**
     * Resolution order:
     * 1) Merchant-specific wallet for coin
     * 2) Explicitly enabled platform custody fallback
     *
     * @param  Merchant  $merchant  Invoice owner.
     * @param  string  $coin  Normalized coin symbol.
     */
    public function resolve(Merchant $merchant, string $coin): ?SuperWallet
    {
        $assetKey = strtolower(trim($coin));

        return $this->resolveByAsset($merchant, $assetKey, $this->registeredNetwork($assetKey, ''));
    }

    public function resolveByAsset(Merchant $merchant, string $assetKey, string $networkKey): ?SuperWallet
    {
        $assetKey = strtolower(trim($assetKey));
        $networkKey = $this->registeredNetwork($assetKey, $networkKey);

        if ($networkKey === '') {
            return null;
        }

        $merchantWallet = $this->resolveMerchantOwnedByAsset($merchant, $assetKey, $networkKey);
        if ($merchantWallet !== null) {
            return $merchantWallet;
        }

        if (! (bool) config('forwarding.allow_platform_wallet_fallback', false)) {
            return null;
        }

        return $this->resolvePlatformFallbackByAsset($assetKey, $networkKey);
    }

    public function resolveMerchantOwnedByAsset(Merchant $merchant, string $assetKey, string $networkKey): ?SuperWallet
    {
        $assetKey = strtolower(trim($assetKey));
        $networkKey = $this->registeredNetwork($assetKey, $networkKey);

        if ($networkKey === '') {
            return null;
        }

        return $merchant->superWallets()
            ->where('asset_key', $assetKey)
            ->where('network_key', $networkKey)
            ->first()
            ?? $merchant->superWallets()
                ->where('coin', $assetKey)
                ->where('network_key', $networkKey)
                ->first()
            ?? $merchant->superWallets()
                ->whereNull('network_key')
                ->where(function ($query) use ($assetKey): void {
                    $query->where('asset_key', $assetKey)->orWhere('coin', $assetKey);
                })
                ->first();
    }

    public function resolvePlatformFallbackByAsset(string $assetKey, string $networkKey): ?SuperWallet
    {
        $assetKey = strtolower(trim($assetKey));
        $networkKey = $this->registeredNetwork($assetKey, $networkKey);

        if ($networkKey === '') {
            return null;
        }

        return SuperWallet::query()
            ->whereNull('merchant_id')
            ->where('asset_key', $assetKey)
            ->where('network_key', $networkKey)
            ->first()
            ?? SuperWallet::query()
                ->whereNull('merchant_id')
                ->where('coin', $assetKey)
                ->where('network_key', $networkKey)
                ->first()
            ?? SuperWallet::query()
                ->whereNull('merchant_id')
                ->whereNull('network_key')
                ->where(function ($query) use ($assetKey): void {
                    $query->where('asset_key', $assetKey)->orWhere('coin', $assetKey);
                })
                ->first();
    }

    /**
     * Null-network wallets are a legacy compatibility path. They are eligible only
     * when the requested pair matches the registry's canonical asset network.
     */
    private function registeredNetwork(string $assetKey, string $requestedNetwork): string
    {
        if (! $this->assets->exists($assetKey, false)) {
            return '';
        }

        $registeredNetwork = strtolower(trim($this->assets->network($assetKey)));
        $requestedNetwork = strtolower(trim($requestedNetwork));

        return $requestedNetwork === '' || $requestedNetwork === $registeredNetwork
            ? $registeredNetwork
            : '';
    }
}
