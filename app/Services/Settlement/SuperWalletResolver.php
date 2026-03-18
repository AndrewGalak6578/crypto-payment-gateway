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
        $merchantWallet = $merchant
            ->superWallets()
            ->where('coin', $coin)
            ->first();

        if ($merchantWallet) {
            return $merchantWallet;
        }

        return SuperWallet::query()
            ->whereNull('merchant_id')
            ->where('coin', $coin)
            ->first();
    }
}
