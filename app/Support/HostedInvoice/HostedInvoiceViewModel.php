<?php

declare(strict_types=1);

namespace App\Support\HostedInvoice;

use App\Models\Invoice;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

final readonly class HostedInvoiceViewModel
{
    /**
     * @param  array<int, array<string, string>>  $assets
     * @param  array<string, mixed>  $invoiceData
     * @param  array<string, string|null>  $redirects
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public Invoice $invoice,
        public array $assets,
        public array $invoiceData,
        public array $redirects,
        public array $settings,
        public string $statusUrl,
        public string $selectAssetUrl,
        public string $paymentUri,
    ) {}

    public static function make(
        Invoice $invoice,
        AssetRegistry $assets,
        ChainRegistry $chains,
        string $statusUrl,
        string $selectAssetUrl,
        string $paymentUri
    ): self {
        return new self(
            invoice: $invoice,
            assets: self::availableAssets($invoice, $assets),
            invoiceData: self::invoiceData($invoice, $assets, $chains, $paymentUri),
            redirects: self::redirects($invoice),
            settings: self::settings($invoice),
            statusUrl: $statusUrl,
            selectAssetUrl: $selectAssetUrl,
            paymentUri: $paymentUri,
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function availableAssets(Invoice $invoice, AssetRegistry $assets): array
    {
        $allowedAssets = $invoice->merchant?->checkout_allowed_assets ?? [];

        return collect($assets->enabled())
            ->when($allowedAssets !== [], fn ($collection) => $collection->only($allowedAssets))
            ->map(fn (array $asset, string $key): array => [
                'key' => $key,
                'symbol' => (string) ($asset['symbol'] ?? strtoupper($key)),
                'name' => (string) ($asset['display_name'] ?? strtoupper($key)),
                'network' => (string) ($asset['network'] ?? ''),
                'network_label' => self::networkLabel((string) ($asset['network'] ?? '')),
                'type' => (string) ($asset['type'] ?? 'native'),
            ])
            ->values()
            ->all();
    }

    private static function settings(Invoice $invoice): array
    {
        $merchant = $invoice->merchant;

        return [
            'display_name' => $merchant?->checkout_display_name ?: $merchant?->name ?: 'Merchant checkout',
            'support_email' => $merchant?->checkout_support_email,
            'show_invoice_id' => $merchant?->checkout_show_invoice_id ?? true,
            'show_support_email' => $merchant?->checkout_show_support_email ?? true,
            'partial_payment_policy' => $merchant?->checkout_partial_payment_policy ?: 'allow_top_up',
            'confirmation_display' => $merchant?->checkout_confirmation_display ?: 'simple',
            'auto_redirect' => $merchant?->checkout_auto_redirect ?? true,
            'redirect_delay_seconds' => $merchant?->checkout_redirect_delay_seconds ?? 5,
            'brand_color' => $merchant?->checkout_brand_color,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoiceData(
        Invoice $invoice,
        AssetRegistry $assets,
        ChainRegistry $chains,
        string $paymentUri
    ): array {
        return [
            'public_id' => $invoice->public_id,
            'status' => $invoice->status,
            'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
            'asset_key' => $invoice->asset_key,
            'asset_label' => self::assetLabel($invoice, $assets),
            'network_key' => $invoice->network_key,
            'network_label' => self::networkLabel((string) $invoice->network_key),
            'payment_mode' => self::paymentMode($invoice, $assets, $chains),
            'pay_address' => $invoice->pay_address,
            'amount_coin' => (string) $invoice->amount_coin,
            'expected_usd' => (string) $invoice->expected_usd,
            'rate_usd' => (string) $invoice->rate_usd,
            'received_conf_coin' => (string) $invoice->received_conf_coin,
            'received_all_coin' => (string) $invoice->received_all_coin,
            'forward_status' => $invoice->forward_status,
            'expires_at' => optional($invoice->expires_at)->toIso8601String(),
            'fixated_at' => optional($invoice->fixated_at)->toIso8601String(),
            'paid_at' => optional($invoice->paid_at)->toIso8601String(),
            'payment_uri' => $paymentUri,
        ];
    }

    private static function paymentMode(Invoice $invoice, AssetRegistry $assets, ChainRegistry $chains): ?string
    {
        $assetKey = $invoice->asset_key ?: $invoice->coin;
        $networkKey = $invoice->network_key;

        if (! $assetKey || ! $networkKey) {
            return null;
        }

        try {
            $asset = $assets->get($assetKey);
            $family = $chains->family($networkKey);
        } catch (RuntimeException) {
            return null;
        }

        if ($family !== 'evm') {
            return 'utxo';
        }

        return (($asset['type'] ?? null) === 'token') ? 'evm_token' : 'evm_native';
    }

    private static function assetLabel(Invoice $invoice, AssetRegistry $assets): ?string
    {
        $assetKey = $invoice->asset_key ?: $invoice->coin;

        if (! $assetKey) {
            return null;
        }

        try {
            $asset = $assets->get($assetKey);
        } catch (RuntimeException) {
            return strtoupper((string) $assetKey);
        }

        return (string) ($asset['display_name'] ?? strtoupper((string) $assetKey));
    }

    private static function networkLabel(string $networkKey): string
    {
        return match ($networkKey) {
            'bitcoin' => 'Bitcoin network',
            'litecoin' => 'Litecoin network',
            'dash' => 'Dash network',
            'evm_local' => 'Local EVM',
            'ethereum' => 'Ethereum',
            'bsc' => 'BNB Smart Chain',
            'polygon' => 'Polygon',
            'arbitrum' => 'Arbitrum',
            'optimism' => 'Optimism',
            'base' => 'Base',
            default => $networkKey !== '' ? strtoupper($networkKey) : '',
        };
    }

    /**
     * @return array<string, string|null>
     */
    private static function redirects(Invoice $invoice): array
    {
        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $redirects = is_array($metadata['redirects'] ?? null) ? $metadata['redirects'] : [];

        $successUrl = self::safeRedirectUrl($redirects['success_url'] ?? null);
        $returnUrl = self::safeRedirectUrl($redirects['return_url'] ?? null);

        return [
            'success_url' => $successUrl,
            'cancel_url' => self::safeRedirectUrl($redirects['cancel_url'] ?? null),
            'return_url' => $returnUrl,
            'complete_url' => $successUrl ?: $returnUrl,
        ];
    }

    private static function safeRedirectUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            return null;
        }

        if (empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
