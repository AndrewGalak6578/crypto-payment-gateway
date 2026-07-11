<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceAssetSelector;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use App\Support\HostedInvoice\HostedInvoiceViewModel;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Public hosted invoice page and status polling endpoint.
 */
class HostedInvoiceController extends Controller
{
    public function __construct(
        private readonly AssetRegistry $assets,
        private readonly ChainRegistry $chains,
    ) {}

    /**
     * Renders hosted invoice page by public identifier.
     *
     * @param  string  $publicId  Public invoice identifier.
     */
    public function show(string $publicId): View
    {
        $invoice = Invoice::query()
            ->with('merchant')
            ->where('public_id', $publicId)
            ->firstOrFail();

        return view('hosted-invoices.checkout', [
            'viewModel' => HostedInvoiceViewModel::make(
                invoice: $invoice,
                assets: $this->assets,
                chains: $this->chains,
                statusUrl: route('hosted-invoice.status', ['publicId' => $invoice->public_id], false),
                selectAssetUrl: route('hosted-invoice.select-asset', ['publicId' => $invoice->public_id], false),
                paymentUri: $this->paymentUri($invoice),
            ),
        ]);
    }

    /**
     * Returns current hosted invoice status snapshot.
     *
     * @param  string  $publicId  Public invoice identifier.
     */
    public function status(string $publicId): JsonResponse
    {
        $invoice = Invoice::query()
            ->with('merchant')
            ->where('public_id', $publicId)
            ->firstOrFail();

        $allowedAssets = $invoice->merchant?->checkout_allowed_assets ?? [];
        if ($allowedAssets !== [] && ! in_array((string) $data['asset_key'], $allowedAssets, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This payment method is not available for this checkout.',
            ], 422);
        }

        if ($invoice->status === 'awaiting_asset' && $invoice->expires_at && now('UTC')->gt($invoice->expires_at)) {
            $invoice->forceFill(['status' => 'expired'])->save();
            $invoice = $invoice->fresh();
        }

        return response()->json([
            'success' => true,
            'data' => $this->statusPayload($invoice),
        ]);
    }

    public function selectAsset(
        Request $request,
        string $publicId,
        InvoiceAssetSelector $selector
    ): JsonResponse {
        $data = $request->validate([
            'asset_key' => ['required', 'string'],
        ]);

        $invoice = Invoice::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        try {
            $invoice = $selector->select($invoice, (string) $data['asset_key']);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => $this->statusPayload($invoice),
        ]);
    }

    /**
     * Builds payment URI consumable by wallet apps.
     */
    private function paymentUri(Invoice $invoice): string
    {
        if (! $invoice->coin || ! $invoice->pay_address) {
            return '';
        }

        $scheme = match ($invoice->coin) {
            'btc' => 'bitcoin',
            'ltc' => 'litecoin',
            'dash' => 'dash',
            default => strtolower($invoice->coin)
        };

        $query = http_build_query([
            'amount' => (string) $invoice->amount_coin,
            'label' => 'Invoice '.$invoice->public_id,
        ]);

        return "{$scheme}:{$invoice->pay_address}?{$query}";
    }

    private function paymentMode(Invoice $invoice): ?string
    {
        $assetKey = $invoice->asset_key ?: $invoice->coin;
        $networkKey = $invoice->network_key;

        if (! $assetKey || ! $networkKey) {
            return null;
        }

        try {
            $asset = $this->assets->get($assetKey);
            $family = $this->chains->family($networkKey);
        } catch (RuntimeException) {
            return null;
        }

        if ($family !== 'evm') {
            return 'utxo';
        }

        return (($asset['type'] ?? null) === 'token') ? 'evm_token' : 'evm_native';
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(Invoice $invoice): array
    {
        return [
            'public_id' => $invoice->public_id,
            'status' => $invoice->status,
            'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
            'asset_key' => $invoice->asset_key,
            'asset_label' => $this->assetLabel($invoice),
            'network_key' => $invoice->network_key,
            'network_label' => $this->networkLabel((string) $invoice->network_key),
            'payment_mode' => $this->paymentMode($invoice),
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
            'payment_uri' => $this->paymentUri($invoice),
        ];
    }

    private function assetLabel(Invoice $invoice): ?string
    {
        $assetKey = $invoice->asset_key ?: $invoice->coin;

        if (! $assetKey) {
            return null;
        }

        try {
            $asset = $this->assets->get($assetKey);
        } catch (RuntimeException) {
            return strtoupper((string) $assetKey);
        }

        return (string) ($asset['display_name'] ?? strtoupper((string) $assetKey));
    }

    private function networkLabel(string $networkKey): string
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
}
