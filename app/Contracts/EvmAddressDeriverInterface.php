<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Services\PaymentAddresses\Evm\DerivedAddressResult;

interface EvmAddressDeriverInterface
{
    public function derive(
        string $networkKey,
        string $keyRef,
        int $index,
        string $pathTemplate
    ): DerivedAddressResult;
}
