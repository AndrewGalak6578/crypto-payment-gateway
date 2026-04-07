<?php
declare(strict_types=1);

namespace App\Data;

final readonly class EvmPaymentDetectionResult
{
    public function __construct(
        public string $receivedAllDecimal,
        public string $receivedConfirmedDecimal,
        public ?string $firstTxHash = null,
        public ?string $firstAmountDecimal = null,
        public ?int $firstSeenAt = null,
        public ?int $firstConfirmedBlock = null,
        public int $currentBlock = 0,
        public int $requiredConfirmations = 1,
        public array $transactions = []

    )
    {
    }
}
