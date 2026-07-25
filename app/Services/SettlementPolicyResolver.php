<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SettlementPolicyConfiguration;
use App\Data\SettlementPolicyDecision;
use App\Models\AssetPolicy;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantAssetPolicy;
use App\Models\MerchantSettlementPreference;
use App\Services\Settlement\SettlementAmountCalculator;
use App\Services\Settlement\SettlementDecimal;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class SettlementPolicyResolver
{
    private const ADMIN_MODE_RESTRICTIONS = [
        AssetPolicy::MODE_IMMEDIATE => [
            AssetPolicy::MODE_IMMEDIATE,
            AssetPolicy::MODE_THRESHOLD,
            AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
            AssetPolicy::MODE_MANUAL,
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_THRESHOLD => [
            AssetPolicy::MODE_THRESHOLD,
            AssetPolicy::MODE_MANUAL,
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_INTERNAL_BALANCE_ONLY => [
            AssetPolicy::MODE_INTERNAL_BALANCE_ONLY,
            AssetPolicy::MODE_MANUAL,
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_MANUAL => [
            AssetPolicy::MODE_MANUAL,
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_DISABLED => [
            AssetPolicy::MODE_DISABLED,
        ],
    ];

    private const MERCHANT_MODE_RESTRICTIONS = [
        AssetPolicy::MODE_IMMEDIATE => [
            AssetPolicy::MODE_IMMEDIATE,
            AssetPolicy::MODE_THRESHOLD,
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_THRESHOLD => [
            AssetPolicy::MODE_THRESHOLD,
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_INTERNAL_BALANCE_ONLY => [
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_MANUAL => [
            AssetPolicy::MODE_DISABLED,
        ],
        AssetPolicy::MODE_DISABLED => [
            AssetPolicy::MODE_DISABLED,
        ],
    ];

    public function __construct(
        private readonly SettlementAmountCalculator $amounts,
        private readonly SettlementDecimal $decimal,
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
    ) {}

    public function resolveForInvoice(Invoice $invoice, bool $lockPolicies = false): SettlementPolicyDecision
    {
        $configuration = $this->resolveForMerchantAsset(
            merchant: $invoice->merchant,
            assetKey: $invoice->resolvedAssetKey(),
            networkKey: $invoice->resolvedNetworkKey(),
            lock: $lockPolicies,
        );
        $remainingAmount = $this->amounts->remainingPayoutCoin($invoice);
        $snapshot = $configuration->snapshot();

        if (BigDecimal::of($remainingAmount)->isZero()) {
            return $this->decision($configuration, 'nothing_to_forward', true, $remainingAmount, $snapshot);
        }

        if (! $configuration->assetEnabled) {
            return $this->decision($configuration, 'asset_disabled_by_policy', false, $remainingAmount, $snapshot);
        }

        if ($configuration->effectiveMode === AssetPolicy::MODE_INTERNAL_BALANCE_ONLY) {
            return $this->decision($configuration, 'internal_balance_only', false, $remainingAmount, $snapshot);
        }

        if ($configuration->effectiveMode === AssetPolicy::MODE_DISABLED) {
            $reason = ! $configuration->forwardingEnabled
                ? 'forwarding_disabled_by_policy'
                : 'settlement_disabled_by_policy';

            return $this->decision($configuration, $reason, false, $remainingAmount, $snapshot);
        }

        if ($configuration->effectiveMode === AssetPolicy::MODE_MANUAL) {
            return $this->decision($configuration, 'manual_settlement_required', false, $remainingAmount, $snapshot);
        }

        if (! $configuration->forwardingAllowed()) {
            return $this->decision($configuration, 'forwarding_disabled_by_policy', false, $remainingAmount, $snapshot);
        }

        if ($configuration->effectiveMode === AssetPolicy::MODE_THRESHOLD) {
            $minimum = $configuration->effectiveMinimumInvoicePayout;

            if ($minimum === null || BigDecimal::of($minimum)->compareTo(BigDecimal::zero()) <= 0) {
                return $this->decision($configuration, 'threshold_not_configured', false, $remainingAmount, $snapshot);
            }

            if (BigDecimal::of($remainingAmount)->compareTo(BigDecimal::of($minimum)) < 0) {
                return $this->decision($configuration, 'below_threshold', false, $remainingAmount, $snapshot);
            }
        }

        return $this->decision($configuration, 'forwarding_allowed', true, $remainingAmount, $snapshot);
    }

    public function resolveForMerchantAsset(
        Merchant $merchant,
        string $assetKey,
        ?string $networkKey = null,
        bool $lock = false,
    ): SettlementPolicyConfiguration {
        $assetKey = strtolower(trim($assetKey));
        if (! $this->assets->exists($assetKey, false)) {
            throw new RuntimeException("Unsupported asset [{$assetKey}].");
        }

        $asset = $this->assets->get($assetKey);
        $networkKey = strtolower(trim((string) ($networkKey ?: $asset['network'] ?? '')));
        if ($networkKey === '' || strtolower((string) ($asset['network'] ?? '')) !== $networkKey) {
            throw new RuntimeException("Asset [{$assetKey}] does not belong to network [{$networkKey}].");
        }

        $chain = $this->chains->get($networkKey);
        $global = $this->globalPolicy($assetKey, $networkKey, $lock);
        $wildcard = $this->merchantAdminPolicy($merchant->id, MerchantAssetPolicy::SCOPE_ALL, $lock);
        $exact = $this->merchantAdminPolicy(
            $merchant->id,
            MerchantAssetPolicy::scopeKey($assetKey, $networkKey),
            $lock,
        );
        $preference = $this->preference($merchant->id, $assetKey, $networkKey, $lock);

        $constraints = [];
        $assetEnabled = (bool) ($asset['enabled'] ?? false) && (bool) ($chain['enabled'] ?? false);
        $forwardingEnabled = $assetEnabled;

        if (! (bool) ($asset['enabled'] ?? false)) {
            $constraints[] = $this->constraint('registry_asset_disabled', 'registry', 'The asset is disabled in the platform registry.');
        }
        if (! (bool) ($chain['enabled'] ?? false)) {
            $constraints[] = $this->constraint('registry_network_disabled', 'registry', 'The asset network is disabled in the platform registry.');
        }

        $mode = $this->defaultMode($assetKey, $networkKey);
        $modeSource = 'registry_default';
        $minimum = $this->configMinimum($assetKey);
        $minimumSource = $minimum === null ? null : 'registry_config';
        $maxGasCost = null;
        $maxGasCostSource = null;

        foreach ([
            ['policy' => $global, 'source' => 'global_asset_policy'],
            ['policy' => $wildcard, 'source' => 'merchant_admin_wildcard'],
            ['policy' => $exact, 'source' => 'merchant_admin_exact'],
        ] as $layer) {
            /** @var AssetPolicy|MerchantAssetPolicy|null $policy */
            $policy = $layer['policy'];
            $source = $layer['source'];
            if ($policy === null) {
                continue;
            }

            if ($policy->asset_enabled === false) {
                $assetEnabled = false;
                $constraints[] = $this->constraint(
                    'asset_disabled_by_admin',
                    $source,
                    $policy->reason ?: 'Asset use is disabled by platform policy.',
                );
            }

            if ($policy->forwarding_enabled === false) {
                $forwardingEnabled = false;
                $constraints[] = $this->constraint(
                    'forwarding_disabled_by_admin',
                    $source,
                    $policy->reason ?: 'Forwarding is disabled by platform policy.',
                );
            }

            $layerMode = $this->normalizeMode($policy->settlement_mode);
            if ($policy->settlement_mode !== null) {
                if ($layerMode === null) {
                    $mode = AssetPolicy::MODE_DISABLED;
                    $modeSource = $source;
                    $constraints[] = $this->constraint(
                        'invalid_admin_settlement_mode',
                        $source,
                        'The stored platform settlement mode is invalid, so settlement is paused.',
                    );
                } elseif (
                    $source === 'global_asset_policy'
                    || $this->canAdminRestrictMode($mode, $layerMode)
                ) {
                    $mode = $layerMode;
                    $modeSource = $source;
                } elseif ($layerMode !== $mode) {
                    $constraints[] = $this->constraint(
                        'admin_mode_not_monotonic',
                        $source,
                        'This platform settlement mode cannot override a different upstream accounting path.',
                    );
                }
            }

            $layerMinimum = $this->decimalValue($policy->min_sweep_amount, $assetKey);
            if ($layerMinimum !== null && (
                $source === 'global_asset_policy'
                || $minimum === null
                || BigDecimal::of($layerMinimum)->compareTo(BigDecimal::of($minimum)) > 0
            )) {
                $minimum = $layerMinimum;
                $minimumSource = $source;
            }

            $layerMaxGasCost = $this->decimalValue($policy->max_gas_cost);
            if ($layerMaxGasCost !== null && (
                $source === 'global_asset_policy'
                || $maxGasCost === null
                || BigDecimal::of($layerMaxGasCost)->compareTo(BigDecimal::of($maxGasCost)) < 0
            )) {
                $maxGasCost = $layerMaxGasCost;
                $maxGasCostSource = $source;
            }
        }

        if (! $assetEnabled) {
            $forwardingEnabled = false;
            $mode = AssetPolicy::MODE_DISABLED;
            $modeSource = $constraints[0]['source'] ?? 'registry';
        } elseif (! $forwardingEnabled) {
            $mode = AssetPolicy::MODE_DISABLED;
            $modeSource = collect($constraints)->firstWhere('code', 'forwarding_disabled_by_admin')['source'] ?? 'platform_policy';
        }

        $inheritedMode = $mode;
        $requestedMode = $this->normalizeMode($preference?->requested_mode);
        $requestedMinimum = $this->decimalValue($preference?->requested_minimum_invoice_payout, $assetKey);
        $effectiveMode = $mode;
        $effectiveMinimum = $minimum;

        if ($preference?->requested_mode !== null && $requestedMode === null) {
            $effectiveMode = AssetPolicy::MODE_DISABLED;
            $constraints[] = $this->constraint(
                'invalid_merchant_preference',
                'merchant_preference',
                'The stored merchant preference is invalid and settlement is paused.',
            );
        } elseif ($requestedMode !== null) {
            if ($this->canMerchantRequestMode($effectiveMode, $requestedMode)) {
                $effectiveMode = $requestedMode;
            } else {
                $constraints[] = $this->constraint(
                    'merchant_request_overridden_by_admin',
                    $modeSource,
                    'Platform policy is more restrictive than the merchant request.',
                );
            }
        }

        if ($requestedMode === AssetPolicy::MODE_THRESHOLD && $requestedMinimum !== null) {
            if ($effectiveMinimum === null || BigDecimal::of($requestedMinimum)->compareTo(BigDecimal::of($effectiveMinimum)) > 0) {
                $effectiveMinimum = $requestedMinimum;
            }
        }

        return new SettlementPolicyConfiguration(
            assetKey: $assetKey,
            networkKey: $networkKey,
            assetEnabled: $assetEnabled,
            forwardingEnabled: $forwardingEnabled,
            inheritedMode: $inheritedMode,
            inheritedMinimumInvoicePayout: $minimum,
            maxGasCost: $maxGasCost,
            requestedMode: $requestedMode,
            requestedMinimumInvoicePayout: $requestedMinimum,
            revision: (int) ($preference?->revision ?? 0),
            effectiveMode: $effectiveMode,
            effectiveMinimumInvoicePayout: $effectiveMinimum,
            modeSource: $modeSource,
            minimumSource: $minimumSource,
            maxGasCostSource: $maxGasCostSource,
            constraints: $constraints,
        );
    }

    private function decision(
        SettlementPolicyConfiguration $configuration,
        string $reason,
        bool $forwardingAllowed,
        string $remainingAmount,
        array $snapshot,
    ): SettlementPolicyDecision {
        return new SettlementPolicyDecision(
            mode: $configuration->effectiveMode,
            reason: $reason,
            minSweepAmount: $configuration->effectiveMinimumInvoicePayout,
            maxGasCost: $configuration->maxGasCost,
            forwardingAllowed: $forwardingAllowed,
            assetKey: $configuration->assetKey,
            networkKey: $configuration->networkKey,
            remainingAmount: $remainingAmount,
            policySnapshot: $snapshot,
        );
    }

    private function globalPolicy(string $assetKey, string $networkKey, bool $lock): ?AssetPolicy
    {
        $query = AssetPolicy::query()
            ->where('asset_key', $assetKey)
            ->where('network_key', $networkKey);

        return $this->locked($query, $lock)->first();
    }

    private function merchantAdminPolicy(int $merchantId, string $scopeKey, bool $lock): ?MerchantAssetPolicy
    {
        $query = MerchantAssetPolicy::query()
            ->where('merchant_id', $merchantId)
            ->where('scope_key', $scopeKey);

        return $this->locked($query, $lock)->first();
    }

    private function preference(int $merchantId, string $assetKey, string $networkKey, bool $lock): ?MerchantSettlementPreference
    {
        $query = MerchantSettlementPreference::query()
            ->where('merchant_id', $merchantId)
            ->where('asset_key', $assetKey)
            ->where('network_key', $networkKey);

        return $this->locked($query, $lock)->first();
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model */
    private function locked(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }

    private function defaultMode(string $assetKey, string $networkKey): string
    {
        $asset = $this->assets->get($assetKey);

        return $this->chains->family($networkKey) === 'evm'
            && strtolower((string) ($asset['type'] ?? 'native')) === 'token'
            && strtolower((string) ($asset['token_standard'] ?? '')) === 'erc20'
                ? AssetPolicy::MODE_THRESHOLD
                : AssetPolicy::MODE_IMMEDIATE;
    }

    private function normalizeMode(mixed $mode): ?string
    {
        if (! is_string($mode)) {
            return null;
        }

        $mode = strtolower(trim($mode));

        return in_array($mode, AssetPolicy::MODES, true) ? $mode : null;
    }

    public function canMerchantRequestMode(string $inheritedMode, string $requestedMode): bool
    {
        return in_array(
            $requestedMode,
            self::MERCHANT_MODE_RESTRICTIONS[$inheritedMode] ?? [],
            true,
        );
    }

    private function canAdminRestrictMode(string $inheritedMode, string $candidateMode): bool
    {
        return in_array(
            $candidateMode,
            self::ADMIN_MODE_RESTRICTIONS[$inheritedMode] ?? [],
            true,
        );
    }

    private function configMinimum(string $assetKey): ?string
    {
        $value = config("forwarding.assets.{$assetKey}.min");

        return $value === null ? null : $this->decimal->format($value, $assetKey);
    }

    private function decimalValue(mixed $value, ?string $assetKey = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $assetKey === null
            ? (string) BigDecimal::of((string) $value)
            : $this->decimal->format($value, $assetKey);
    }

    /** @return array{code: string, source: string, message: string} */
    private function constraint(string $code, string $source, string $message): array
    {
        return compact('code', 'source', 'message');
    }
}
