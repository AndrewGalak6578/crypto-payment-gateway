<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\AssetPolicy;

final readonly class SettlementPolicyDecision
{
    public function __construct(
        public string $mode,
        public string $reason,
        public ?string $minSweepAmount,
        public ?string $maxGasCost,
        public bool $forwardingAllowed,
        public string $assetKey,
        public string $networkKey,
        public string $remainingAmount,
    ) {}

    public function shouldCreditInternalBalance(): bool
    {
        return $this->mode === AssetPolicy::MODE_INTERNAL_BALANCE_ONLY;
    }

    public function shouldHold(): bool
    {
        return ! $this->forwardingAllowed && ! $this->shouldCreditInternalBalance();
    }
}
