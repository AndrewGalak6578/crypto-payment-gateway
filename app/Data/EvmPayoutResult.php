<?php
declare(strict_types=1);

namespace App\Data;

final readonly class EvmPayoutResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $txHash,
        public string $fromAddress,
        public string $toAddress,
        public string $amountDecimal,
        public ?int $nonce = null,
        public ?string $gasPriceWei = null,
        public ?string $gasLimit = null,
        public ?string $maxFeePerGasWei = null,
        public ?string $maxPriorityFeePerGasWei = null,
        public array $meta = []
    )
    {
    }
}
