<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\AssetPolicy;

final readonly class SettlementPolicyConfiguration
{
    /** @param list<array{code: string, source: string, message: string}> $constraints */
    public function __construct(
        public string $assetKey,
        public string $networkKey,
        public bool $assetEnabled,
        public bool $forwardingEnabled,
        public string $inheritedMode,
        public ?string $inheritedMinimumInvoicePayout,
        public ?string $maxGasCost,
        public ?string $requestedMode,
        public ?string $requestedMinimumInvoicePayout,
        public int $revision,
        public string $effectiveMode,
        public ?string $effectiveMinimumInvoicePayout,
        public string $modeSource,
        public ?string $minimumSource,
        public ?string $maxGasCostSource,
        public array $constraints,
    ) {}

    public function forwardingAllowed(): bool
    {
        return $this->assetEnabled
            && $this->forwardingEnabled
            && in_array($this->effectiveMode, [
                AssetPolicy::MODE_IMMEDIATE,
                AssetPolicy::MODE_THRESHOLD,
            ], true);
    }

    /** @return array{mode: ?string, minimum_invoice_payout: ?string} */
    public function requestedValues(): array
    {
        return [
            'mode' => $this->requestedMode,
            'minimum_invoice_payout' => $this->requestedMinimumInvoicePayout,
        ];
    }

    /** @return array<string, mixed> */
    public function inheritedValues(): array
    {
        return [
            'asset_enabled' => $this->assetEnabled,
            'forwarding_enabled' => $this->forwardingEnabled,
            'mode' => $this->inheritedMode,
            'minimum_invoice_payout' => $this->inheritedMinimumInvoicePayout,
            'max_gas_cost' => [
                'amount' => $this->maxGasCost,
                'enforcement' => false,
            ],
            'sources' => [
                'mode' => $this->modeSource,
                'minimum_invoice_payout' => $this->minimumSource,
                'max_gas_cost' => $this->maxGasCostSource,
            ],
            'constraints' => $this->constraints,
        ];
    }

    /** @return array<string, mixed> */
    public function effectiveValues(): array
    {
        $restriction = $this->constraints[0] ?? null;

        return [
            'mode' => $this->effectiveMode,
            'minimum_invoice_payout' => $this->effectiveMinimumInvoicePayout,
            'forwarding_allowed' => $this->forwardingAllowed(),
            'restriction' => $restriction === null ? null : [
                'reason_code' => $restriction['code'],
                'source' => $restriction['source'],
                'message' => $restriction['message'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'asset_key' => $this->assetKey,
            'network_key' => $this->networkKey,
            'revision' => $this->revision,
            'requested' => $this->requestedValues(),
            'inherited' => $this->inheritedValues(),
            'effective' => $this->effectiveValues(),
            'evaluated_at' => now('UTC')->toIso8601String(),
        ];
    }
}
