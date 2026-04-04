<?php
declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\Merchant;
use App\Models\SuperWallet;

/**
 * Resolves forwarding destination wallet with merchant override support.
 */
final class SuperWalletResolver
{
    /**
     * Resolution order:
     * 1) Merchant-specific wallet for coin
     * 2) Global fallback wallet for coin
     *
     * @param Merchant $merchant Invoice owner.
     * @param string $coin Normalized coin symbol.
     * @return SuperWallet|null
     */
    public function resolve(Merchant $merchant, string $coin): ?SuperWallet
    {
        return $this->resolveByAsset(
            merchant: $merchant,
            assetKey: strtolower($coin),
            networkKey: '',
        );
    }

    public function resolveByAsset(Merchant $merchant, string $assetKey, string $networkKey): ?SuperWallet
    {
        $assetKey = strtolower($assetKey);
        $networkKey = strtolower($networkKey);

        $merchantWallet = $merchant->superWallets()
            ->when($networkKey !== '', fn($query) => $query->where('network_key', $networkKey))
            ->where('asset_key', $assetKey)
            ->first();

        if ($merchantWallet) {
            return $merchantWallet;
        }

        $globalWallet = SuperWallet::query()
            ->whereNull('merchant_id')
            ->where('asset_key', $assetKey)
            ->when($networkKey !== '', fn($query) => $query->where('network_key', $networkKey))
            ->first();

        if ($globalWallet) {
            return $globalWallet;
        }

        $merchantLegacy = $merchant->superWallets()
            ->where('coin', $assetKey)
            ->first();

        if ($merchantLegacy) {
            return $merchantLegacy;
        }

        return SuperWallet::query()
            ->whereNull('merchant_id')
            ->where('coin', $assetKey)
            ->first();
    }
}
