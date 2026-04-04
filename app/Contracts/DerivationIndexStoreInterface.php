<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Models\Merchant;

interface DerivationIndexStoreInterface
{
    public function reserveNext(Merchant $merchant, string $networkKey, string $keyRef): int;
}
