<?php
declare(strict_types=1);

namespace App\Data;

final readonly class EvmSweepSource
{
    public function __construct(
        public string $networkKey,
        public string $address,
        public string $keyRef,
        public ?string $derivationPath = null,
        public ?int $derivationIndex = null,
        public string $strategy = 'hd_derived',
        public array $meta = []
    )
    {
    }
}
