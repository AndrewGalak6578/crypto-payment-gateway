<?php

declare(strict_types=1);

namespace App\Data;

final readonly class EvmGasFundingReconciliationResult
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const FAILED_SAFE = 'failed_safe';

    public const INCONCLUSIVE = 'inconclusive';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $outcome,
        public string $reason,
        public ?string $txHash = null,
        public int $confirmations = 0,
        public array $evidence = [],
    ) {}

    /** @param array<string, mixed> $evidence */
    public static function pending(
        string $reason,
        ?string $txHash,
        int $confirmations = 0,
        array $evidence = [],
    ): self {
        return new self(self::PENDING, $reason, $txHash, $confirmations, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public static function confirmed(string $txHash, int $confirmations, array $evidence = []): self
    {
        return new self(self::CONFIRMED, 'confirmed', $txHash, $confirmations, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public static function failedSafe(string $txHash, int $confirmations, array $evidence = []): self
    {
        return new self(self::FAILED_SAFE, 'receipt_failed_without_value_transfer', $txHash, $confirmations, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public static function inconclusive(
        string $reason,
        ?string $txHash = null,
        array $evidence = [],
    ): self {
        return new self(self::INCONCLUSIVE, $reason, $txHash, 0, $evidence);
    }
}
