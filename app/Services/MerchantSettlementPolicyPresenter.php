<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SettlementPolicyConfiguration;
use App\Models\AssetPolicy;
use App\Models\Merchant;
use App\Services\Settlement\SuperWalletResolver;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;

final readonly class MerchantSettlementPolicyPresenter
{
    public function __construct(
        private SettlementPolicyResolver $policies,
        private SuperWalletResolver $wallets,
        private AssetRegistry $assets,
        private ChainRegistry $chains,
    ) {}

    /** @return array<string, mixed> */
    public function present(Merchant $merchant, string $assetKey, bool $canWrite): array
    {
        $assetKey = strtolower($assetKey);
        $asset = $this->assets->get($assetKey);
        $networkKey = (string) $asset['network'];
        $chain = $this->chains->get($networkKey);
        $configuration = $this->policies->resolveForMerchantAsset($merchant, $assetKey, $networkKey);
        $wallet = $this->wallets->resolveMerchantOwnedByAsset($merchant, $assetKey, $networkKey);
        $platformFallback = $this->wallets->resolvePlatformFallbackByAsset($assetKey, $networkKey);
        $requiresWallet = in_array($configuration->effectiveMode, [
            AssetPolicy::MODE_IMMEDIATE,
            AssetPolicy::MODE_THRESHOLD,
        ], true);
        $thresholdEditableByPolicy = $this->policies->canMerchantRequestMode(
            $configuration->inheritedMode,
            AssetPolicy::MODE_THRESHOLD,
        );

        return [
            'asset' => [
                'key' => $assetKey,
                'name' => (string) ($asset['display_name'] ?? strtoupper($assetKey)),
                'symbol' => (string) ($asset['symbol'] ?? strtoupper($assetKey)),
                'type' => (string) ($asset['type'] ?? 'native'),
                'token_standard' => $asset['token_standard'] ?? null,
                'decimals' => (int) ($asset['decimals'] ?? $this->assets->settlementScale($assetKey)),
                'settlement_scale' => $this->assets->settlementScale($assetKey),
                'network' => [
                    'key' => $networkKey,
                    'name' => (string) ($chain['display_name'] ?? $networkKey),
                    'family' => (string) ($chain['family'] ?? ''),
                ],
            ],
            'revision' => $configuration->revision,
            'requested' => $configuration->requestedValues(),
            'inherited' => $configuration->inheritedValues(),
            'effective' => array_merge($configuration->effectiveValues(), [
                'behavior' => $this->behavior($configuration),
                'applies_to' => 'future_policy_evaluations',
            ]),
            'destination_wallet' => [
                'required' => $requiresWallet,
                'ready' => $wallet !== null,
                'source' => $wallet === null ? 'none' : 'merchant',
                'wallet_id' => $wallet?->id,
                'address_masked' => $wallet === null ? null : $this->maskAddress((string) $wallet->wallet),
                'reason_code' => $requiresWallet && $wallet === null ? 'destination_wallet_missing' : null,
                'platform_fallback' => [
                    'configured' => $platformFallback !== null,
                    'allowed' => (bool) config('forwarding.allow_platform_wallet_fallback', false),
                ],
            ],
            'editable' => [
                'mode' => [
                    'allowed' => $canWrite,
                    'reason_code' => $canWrite ? null : 'missing_settlements_write',
                    'options' => $this->modeOptions($configuration, $canWrite),
                ],
                'minimum_invoice_payout' => [
                    'allowed' => $canWrite && $thresholdEditableByPolicy,
                    'minimum' => $configuration->inheritedMinimumInvoicePayout,
                    'scale' => $this->assets->settlementScale($assetKey),
                    'symbol' => (string) ($asset['symbol'] ?? strtoupper($assetKey)),
                    'reason_code' => match (true) {
                        ! $canWrite => 'missing_settlements_write',
                        ! $thresholdEditableByPolicy => 'platform_policy_more_restrictive',
                        default => null,
                    },
                ],
                'max_gas_cost' => [
                    'allowed' => false,
                    'reason_code' => 'gas_cap_not_enforced',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function modeOptions(SettlementPolicyConfiguration $configuration, bool $canWrite): array
    {
        $adminAllowsForwarding = $configuration->assetEnabled && $configuration->forwardingEnabled;

        return [
            $this->option('immediate', 'Immediate', $canWrite && $adminAllowsForwarding && $this->policies->canMerchantRequestMode($configuration->inheritedMode, AssetPolicy::MODE_IMMEDIATE), 'platform_policy_more_restrictive'),
            $this->option('threshold', 'Minimum invoice payout', $canWrite && $adminAllowsForwarding && $this->policies->canMerchantRequestMode($configuration->inheritedMode, AssetPolicy::MODE_THRESHOLD), 'platform_policy_more_restrictive'),
            $this->option('manual', 'Manual', false, 'operator_release_unavailable'),
            $this->option('internal_balance_only', 'Internal balance only', false, 'admin_only_custodial_mode'),
            $this->option('disabled', 'Pause settlements', $canWrite, $canWrite ? null : 'missing_settlements_write'),
        ];
    }

    /** @return array{value: string, label: string, available: bool, reason_code: ?string} */
    private function option(string $value, string $label, bool $available, ?string $unavailableReason): array
    {
        return [
            'value' => $value,
            'label' => $label,
            'available' => $available,
            'reason_code' => $available ? null : $unavailableReason,
        ];
    }

    private function behavior(SettlementPolicyConfiguration $configuration): string
    {
        return match ($configuration->effectiveMode) {
            AssetPolicy::MODE_IMMEDIATE => 'forward_each_paid_invoice',
            AssetPolicy::MODE_THRESHOLD => 'evaluate_minimum_invoice_payout_per_invoice',
            AssetPolicy::MODE_INTERNAL_BALANCE_ONLY => 'credit_internal_balance',
            AssetPolicy::MODE_MANUAL => 'hold_for_operator_release',
            default => 'hold_by_policy',
        };
    }

    private function maskAddress(string $address): string
    {
        return strlen($address) <= 16
            ? $address
            : substr($address, 0, 8).'...'.substr($address, -6);
    }
}
