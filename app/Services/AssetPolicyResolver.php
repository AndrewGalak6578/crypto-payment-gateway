<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssetPolicy;
use App\Models\Merchant;
use App\Models\MerchantAssetPolicy;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use DomainException;
use RuntimeException;

final class AssetPolicyResolver
{
    public function __construct(
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
    ) {}

    public function isAssetEnabled(string $assetKey, ?string $networkKey = null): bool
    {
        $assetKey = strtolower(trim($assetKey));

        if (! $this->assets->exists($assetKey, false)) {
            return false;
        }

        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        if (! $this->catalogAllowsAsset($assetKey, $networkKey)) {
            return false;
        }

        return $this->globalPolicyFor($assetKey, $networkKey)?->asset_enabled !== false;
    }

    public function isCheckoutEnabled(string $assetKey, ?string $networkKey = null): bool
    {
        $assetKey = strtolower(trim($assetKey));

        if (! $this->assets->exists($assetKey, false)) {
            return false;
        }

        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        return $this->isAssetEnabled($assetKey, $networkKey)
            && $this->globalPolicyFor($assetKey, $networkKey)?->checkout_enabled !== false;
    }

    public function isForwardingEnabled(string $assetKey, ?string $networkKey = null): bool
    {
        $assetKey = strtolower(trim($assetKey));

        if (! $this->assets->exists($assetKey, false)) {
            return false;
        }

        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        return $this->isAssetEnabled($assetKey, $networkKey)
            && $this->globalPolicyFor($assetKey, $networkKey)?->forwarding_enabled !== false;
    }

    public function canMerchantUseAsset(Merchant $merchant, string $assetKey, ?string $networkKey = null): bool
    {
        $assetKey = strtolower(trim($assetKey));

        if (! $this->assets->exists($assetKey, false)) {
            return false;
        }

        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        return $this->isAssetEnabled($assetKey, $networkKey)
            && ! $this->merchantBlocks($merchant, $assetKey, $networkKey, 'asset_enabled');
    }

    public function canMerchantCheckoutAsset(
        Merchant $merchant,
        string $assetKey,
        ?string $networkKey = null,
        bool $applyMerchantCheckoutSettings = true
    ): bool {
        $assetKey = strtolower(trim($assetKey));

        if (! $this->assets->exists($assetKey, false)) {
            return false;
        }

        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        if (
            ! $this->canMerchantUseAsset($merchant, $assetKey, $networkKey)
            || ! $this->isCheckoutEnabled($assetKey, $networkKey)
            || $this->merchantBlocks($merchant, $assetKey, $networkKey, 'checkout_enabled')
        ) {
            return false;
        }

        if ($applyMerchantCheckoutSettings) {
            $allowedAssets = $merchant->checkout_allowed_assets ?? [];

            if ($allowedAssets !== [] && ! in_array($assetKey, $allowedAssets, true)) {
                return false;
            }
        }

        return true;
    }

    public function canMerchantForwardAsset(Merchant $merchant, string $assetKey, ?string $networkKey = null): bool
    {
        $assetKey = strtolower(trim($assetKey));

        if (! $this->assets->exists($assetKey, false)) {
            return false;
        }

        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        return $this->canMerchantUseAsset($merchant, $assetKey, $networkKey)
            && $this->isForwardingEnabled($assetKey, $networkKey)
            && ! $this->merchantBlocks($merchant, $assetKey, $networkKey, 'forwarding_enabled');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allowedCheckoutAssets(Merchant $merchant): array
    {
        return $this->checkoutAssets($merchant, applyMerchantCheckoutSettings: true);
    }

    /**
     * @return list<string>
     */
    public function allowedCheckoutAssetKeys(Merchant $merchant): array
    {
        return array_values(array_keys($this->allowedCheckoutAssets($merchant)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function configurableCheckoutAssets(Merchant $merchant): array
    {
        return $this->checkoutAssets($merchant, applyMerchantCheckoutSettings: false);
    }

    /**
     * @return list<string>
     */
    public function configurableCheckoutAssetKeys(Merchant $merchant): array
    {
        return array_values(array_keys($this->configurableCheckoutAssets($merchant)));
    }

    public function assertCanCreateInvoice(Merchant $merchant, mixed $assetKey = null, ?string $networkKey = null): void
    {
        $assetKey = is_string($assetKey) ? strtolower(trim($assetKey)) : '';

        if ($assetKey === '') {
            if ($this->allowedCheckoutAssetKeys($merchant) === []) {
                throw new DomainException('No checkout assets are available for this merchant.');
            }

            return;
        }

        if (! $this->canMerchantCheckoutAsset($merchant, $assetKey, $networkKey)) {
            throw new DomainException('Selected asset is not available for this checkout.');
        }
    }

    public function globalPolicyFor(string $assetKey, ?string $networkKey = null): ?AssetPolicy
    {
        $assetKey = strtolower(trim($assetKey));
        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        return AssetPolicy::query()
            ->where('asset_key', $assetKey)
            ->where('network_key', $networkKey)
            ->first();
    }

    public function merchantPolicyFor(Merchant $merchant, string $assetKey, ?string $networkKey = null): ?MerchantAssetPolicy
    {
        $assetKey = strtolower(trim($assetKey));
        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        return MerchantAssetPolicy::query()
            ->where('merchant_id', $merchant->id)
            ->where('scope_key', MerchantAssetPolicy::scopeKey($assetKey, $networkKey))
            ->first();
    }

    public function merchantWildcardPolicy(Merchant $merchant): ?MerchantAssetPolicy
    {
        return MerchantAssetPolicy::query()
            ->where('merchant_id', $merchant->id)
            ->where('scope_key', MerchantAssetPolicy::SCOPE_ALL)
            ->first();
    }

    public function merchantPolicyValue(
        Merchant $merchant,
        string $assetKey,
        ?string $networkKey,
        string $field
    ): mixed {
        $assetKey = strtolower(trim($assetKey));
        $networkKey = $this->networkKeyFor($assetKey, $networkKey);

        $assetPolicy = $this->merchantPolicyFor($merchant, $assetKey, $networkKey);
        if ($assetPolicy && $assetPolicy->getAttribute($field) !== null) {
            return $assetPolicy->getAttribute($field);
        }

        $wildcard = $this->merchantWildcardPolicy($merchant);
        if ($wildcard && $wildcard->getAttribute($field) !== null) {
            return $wildcard->getAttribute($field);
        }

        return null;
    }

    private function catalogAllowsAsset(string $assetKey, string $networkKey): bool
    {
        try {
            $asset = $this->assets->get($assetKey);
            $chain = $this->chains->get($networkKey);
        } catch (RuntimeException) {
            return false;
        }

        return strtolower((string) ($asset['network'] ?? '')) === $networkKey
            && (bool) ($asset['enabled'] ?? false)
            && (bool) ($chain['enabled'] ?? false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function checkoutAssets(Merchant $merchant, bool $applyMerchantCheckoutSettings): array
    {
        $allowed = [];

        foreach ($this->assets->all() as $assetKey => $asset) {
            $networkKey = (string) ($asset['network'] ?? '');

            if ($networkKey === '') {
                continue;
            }

            if ($this->canMerchantCheckoutAsset(
                merchant: $merchant,
                assetKey: (string) $assetKey,
                networkKey: $networkKey,
                applyMerchantCheckoutSettings: $applyMerchantCheckoutSettings,
            )) {
                $allowed[(string) $assetKey] = $asset;
            }
        }

        return $allowed;
    }

    private function merchantBlocks(Merchant $merchant, string $assetKey, string $networkKey, string $field): bool
    {
        foreach ([
            $this->merchantWildcardPolicy($merchant),
            $this->merchantPolicyFor($merchant, $assetKey, $networkKey),
        ] as $policy) {
            if ($policy && $policy->getAttribute($field) === false) {
                return true;
            }
        }

        return false;
    }

    private function networkKeyFor(string $assetKey, ?string $networkKey = null): string
    {
        $networkKey = strtolower(trim((string) $networkKey));

        if ($networkKey !== '') {
            return $networkKey;
        }

        return (string) $this->assets->network($assetKey);
    }
}
