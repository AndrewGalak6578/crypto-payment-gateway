<?php
declare(strict_types=1);

namespace App\Data;

final readonly class EvmGasCheckResult
{
    public function __construct(
        public bool $hasEnoughGas,
        public string $gasLimit,
        public string $gasPriceWei,
        public string $estimatedCostWei,
        public string $nativeBalanceWei,
        public ?string $reason = null,
        public array $meta = []
    )
    {
    }
}
