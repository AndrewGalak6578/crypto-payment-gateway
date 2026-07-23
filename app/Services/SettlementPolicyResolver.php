<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SettlementPolicyDecision;
use App\Models\AssetPolicy;
use App\Models\Invoice;
use App\Services\Settlement\SettlementAmountCalculator;
use App\Services\Settlement\SettlementDecimal;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use Brick\Math\BigDecimal;

final class SettlementPolicyResolver
{
    private const MODE_RANK = [
        AssetPolicy::MODE_DISABLED => 0,
        AssetPolicy::MODE_MANUAL => 1,
        AssetPolicy::MODE_INTERNAL_BALANCE_ONLY => 2,
        AssetPolicy::MODE_THRESHOLD => 3,
        AssetPolicy::MODE_IMMEDIATE => 4,
    ];

    public function __construct(
        private readonly AssetPolicyResolver $assetPolicies,
        private readonly SettlementAmountCalculator $amounts,
        private readonly SettlementDecimal $decimal,
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
    ) {}

    public function resolveForInvoice(Invoice $invoice): SettlementPolicyDecision
    {
        $assetKey = $invoice->resolvedAssetKey();
        $networkKey = $invoice->resolvedNetworkKey();
        $merchant = $invoice->merchant;
        $remainingAmount = $this->amounts->remainingPayoutCoin($invoice);

        $globalPolicy = $this->assetPolicies->globalPolicyFor($assetKey, $networkKey);
        $mode = $this->effectiveMode($invoice, $assetKey, $networkKey);
        $minSweepAmount = $this->effectiveMinSweepAmount($invoice, $assetKey, $networkKey);
        $maxGasCost = $this->effectiveMaxGasCost($invoice, $assetKey, $networkKey);

        if (BigDecimal::of($remainingAmount)->isZero()) {
            return new SettlementPolicyDecision(
                mode: $mode,
                reason: 'nothing_to_forward',
                minSweepAmount: $minSweepAmount,
                maxGasCost: $maxGasCost,
                forwardingAllowed: true,
                assetKey: $assetKey,
                networkKey: $networkKey,
                remainingAmount: $remainingAmount,
            );
        }

        if (! $this->assetPolicies->canMerchantUseAsset($merchant, $assetKey, $networkKey)) {
            return $this->decision(
                AssetPolicy::MODE_DISABLED,
                'asset_disabled_by_policy',
                $minSweepAmount,
                $maxGasCost,
                false,
                $assetKey,
                $networkKey,
                $remainingAmount,
            );
        }

        if ($mode === AssetPolicy::MODE_INTERNAL_BALANCE_ONLY) {
            return $this->decision($mode, 'internal_balance_only', $minSweepAmount, $maxGasCost, false, $assetKey, $networkKey, $remainingAmount);
        }

        if ($mode === AssetPolicy::MODE_DISABLED) {
            return $this->decision($mode, $globalPolicy?->reason ?: 'settlement_disabled_by_policy', $minSweepAmount, $maxGasCost, false, $assetKey, $networkKey, $remainingAmount);
        }

        if ($mode === AssetPolicy::MODE_MANUAL) {
            return $this->decision($mode, 'manual_settlement_required', $minSweepAmount, $maxGasCost, false, $assetKey, $networkKey, $remainingAmount);
        }

        if (! $this->assetPolicies->canMerchantForwardAsset($merchant, $assetKey, $networkKey)) {
            return $this->decision(AssetPolicy::MODE_DISABLED, 'forwarding_disabled_by_policy', $minSweepAmount, $maxGasCost, false, $assetKey, $networkKey, $remainingAmount);
        }

        if ($mode === AssetPolicy::MODE_THRESHOLD) {
            if ($minSweepAmount === null || BigDecimal::of($minSweepAmount)->compareTo(BigDecimal::zero()) <= 0) {
                return $this->decision($mode, 'threshold_not_configured', $minSweepAmount, $maxGasCost, false, $assetKey, $networkKey, $remainingAmount);
            }

            if (BigDecimal::of($remainingAmount)->compareTo(BigDecimal::of($minSweepAmount)) < 0) {
                return $this->decision($mode, 'below_threshold', $minSweepAmount, $maxGasCost, false, $assetKey, $networkKey, $remainingAmount);
            }
        }

        return $this->decision($mode, 'forwarding_allowed', $minSweepAmount, $maxGasCost, true, $assetKey, $networkKey, $remainingAmount);
    }

    private function decision(
        string $mode,
        string $reason,
        ?string $minSweepAmount,
        ?string $maxGasCost,
        bool $forwardingAllowed,
        string $assetKey,
        string $networkKey,
        string $remainingAmount
    ): SettlementPolicyDecision {
        return new SettlementPolicyDecision(
            mode: $mode,
            reason: $reason,
            minSweepAmount: $minSweepAmount,
            maxGasCost: $maxGasCost,
            forwardingAllowed: $forwardingAllowed,
            assetKey: $assetKey,
            networkKey: $networkKey,
            remainingAmount: $remainingAmount,
        );
    }

    private function effectiveMode(Invoice $invoice, string $assetKey, string $networkKey): string
    {
        $mode = $this->defaultMode($assetKey, $networkKey);
        $globalMode = $this->normalizeMode(
            $this->assetPolicies->globalPolicyFor($assetKey, $networkKey)?->settlement_mode
        );

        if ($globalMode !== null) {
            $mode = $globalMode;
        }

        $merchantMode = $this->normalizeMode(
            $this->assetPolicies->merchantPolicyValue($invoice->merchant, $assetKey, $networkKey, 'settlement_mode')
        );

        if ($merchantMode !== null) {
            $mode = $this->mostRestrictiveMode($mode, $merchantMode);
        }

        return $mode;
    }

    private function defaultMode(string $assetKey, string $networkKey): string
    {
        $asset = $this->assets->get($assetKey);

        if (
            $this->chains->family($networkKey) === 'evm'
            && strtolower((string) ($asset['type'] ?? 'native')) === 'token'
            && strtolower((string) ($asset['token_standard'] ?? '')) === 'erc20'
        ) {
            return AssetPolicy::MODE_THRESHOLD;
        }

        return AssetPolicy::MODE_IMMEDIATE;
    }

    private function effectiveMinSweepAmount(Invoice $invoice, string $assetKey, string $networkKey): ?string
    {
        $globalPolicy = $this->assetPolicies->globalPolicyFor($assetKey, $networkKey);
        $platformMin = $this->decimalValue($globalPolicy?->min_sweep_amount, $assetKey)
            ?? $this->configMinSweepAmount($assetKey);

        $merchantMin = $this->decimalValue(
            $this->assetPolicies->merchantPolicyValue($invoice->merchant, $assetKey, $networkKey, 'min_sweep_amount'),
            $assetKey,
        );

        if ($merchantMin === null) {
            return $platformMin;
        }

        if ($platformMin === null) {
            return $merchantMin;
        }

        return BigDecimal::of($platformMin)->compareTo(BigDecimal::of($merchantMin)) >= 0
            ? $platformMin
            : $merchantMin;
    }

    private function effectiveMaxGasCost(Invoice $invoice, string $assetKey, string $networkKey): ?string
    {
        $globalMax = $this->decimalValue(
            $this->assetPolicies->globalPolicyFor($assetKey, $networkKey)?->max_gas_cost
        );
        $merchantMax = $this->decimalValue(
            $this->assetPolicies->merchantPolicyValue($invoice->merchant, $assetKey, $networkKey, 'max_gas_cost')
        );

        if ($globalMax === null) {
            return $merchantMax;
        }

        if ($merchantMax === null) {
            return $globalMax;
        }

        return BigDecimal::of($globalMax)->compareTo(BigDecimal::of($merchantMax)) <= 0
            ? $globalMax
            : $merchantMax;
    }

    private function mostRestrictiveMode(string $platformMode, string $merchantMode): string
    {
        return self::MODE_RANK[$merchantMode] < self::MODE_RANK[$platformMode]
            ? $merchantMode
            : $platformMode;
    }

    private function normalizeMode(mixed $mode): ?string
    {
        if (! is_string($mode)) {
            return null;
        }

        $mode = strtolower(trim($mode));

        return in_array($mode, AssetPolicy::MODES, true) ? $mode : null;
    }

    private function configMinSweepAmount(string $assetKey): ?string
    {
        $value = config("forwarding.assets.{$assetKey}.min");

        return $value === null ? null : $this->decimal->format($value, $assetKey);
    }

    private function decimalValue(mixed $value, ?string $assetKey = null): ?string
    {
        if ($value === null) {
            return null;
        }

        return $assetKey === null
            ? (string) BigDecimal::of((string) $value)
            : $this->decimal->format($value, $assetKey);
    }
}
