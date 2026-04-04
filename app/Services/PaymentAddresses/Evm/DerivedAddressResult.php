<?php
declare(strict_types=1);

namespace App\Services\PaymentAddresses\Evm;

final readonly class DerivedAddressResult
{
    public function __construct(
        public string  $address,
        public ?string $derivationPath = null,
        public ?int $derivationIndex = null,
        public ?string $keyRef = null,
        public array $meta = [],
    )
    {
    }
}
