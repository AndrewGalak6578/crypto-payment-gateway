<?php
declare(strict_types=1);

namespace App\Contracts;

interface DerivationIndexStoreInterface
{
    public function reserveNext(string $family, string $networkKey, string $keyRef): int;
}
