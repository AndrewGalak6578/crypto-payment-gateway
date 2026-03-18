<?php
declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\Merchant;
use App\Models\SuperWallet;

final class SuperWalletResolver
{
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
