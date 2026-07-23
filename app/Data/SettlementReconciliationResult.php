<?php

declare(strict_types=1);

namespace App\Data;

final readonly class SettlementReconciliationResult
{
    public const CONFIRMED = 'confirmed';

    public const FAILED_SAFE = 'failed_safe';

    public const PENDING = 'pending';

    public const INCONCLUSIVE = 'inconclusive';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $outcome,
        public string $reason,
        public ?string $txid = null,
        public int $confirmations = 0,
        public array $evidence = [],
    ) {}

    public static function confirmed(string $txid, int $confirmations, array $evidence = []): self
    {
        return new self(self::CONFIRMED, 'payout_confirmed', $txid, $confirmations, $evidence);
    }

    public static function failedSafe(string $txid, int $confirmations, array $evidence = []): self
    {
        return new self(self::FAILED_SAFE, 'confirmed_failed_evm_receipt', $txid, $confirmations, $evidence);
    }

    public static function pending(string $reason, ?string $txid = null, int $confirmations = 0, array $evidence = []): self
    {
        return new self(self::PENDING, $reason, $txid, $confirmations, $evidence);
    }

    public static function inconclusive(string $reason, ?string $txid = null, array $evidence = []): self
    {
        return new self(self::INCONCLUSIVE, $reason, $txid, 0, $evidence);
    }
}
