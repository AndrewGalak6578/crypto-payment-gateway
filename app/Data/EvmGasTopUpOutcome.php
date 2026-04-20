<?php
declare(strict_types=1);

namespace App\Data;

final readonly class EvmGasTopUpOutcome
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $status,
        public bool $requiresDeferredPayout,
        public ?string $txHash = null,
        public ?string $fundedAmountWei = null,
        public ?string $gasStationAddress = null,
        public int $retryAfterSeconds = 30,
        public array $meta = [],
    )
    {
    }
}
