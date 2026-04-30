<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceStatusRefresher;
use App\Support\Assets\AssetRegistry;
use App\Support\Chains\ChainRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Public hosted invoice page and status polling endpoint.
 */
class HostedInvoiceController extends Controller
{
    /**
     * Renders hosted invoice page by public identifier.
     *
     * @param string $publicId Public invoice identifier.
     */
    public function show(string $publicId): View
    {
        $invoice = Invoice::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        return view('hosted-invoices.show', [
            'invoice' => $invoice,
            'paymentUri' => $this->paymentUri($invoice),
            'statusUrl' => route('hosted-invoice.status', ['publicId' => $invoice->public_id], false)
        ]);
    }

    /**
     * Returns current hosted invoice status snapshot.
     *
     * @param string $publicId Public invoice identifier.
     * @param Request $request
     * @param InvoiceStatusRefresher $refresher
     */
    public function status(
        string $publicId,
        Request $request,
        InvoiceStatusRefresher $refresher
    ): JsonResponse
    {
        $invoice = Invoice::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        if ($request->boolean('refresh')) {
            $invoice = $refresher->refresh($invoice);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'public_id' => $invoice->public_id,
                'status' => $invoice->status,
                'coin' => strtoupper($invoice->coin),
                'asset_key' => $invoice->asset_key,
                'network_key' => $invoice->network_key,
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
            ],
        ]);
    }

    /**
     * Builds payment URI consumable by wallet apps.
     *
     * @param Invoice $invoice
     */
    private function paymentUri(Invoice $invoice): string
    {
        $scheme = match ($invoice->coin) {
            'btc' => 'bitcoin',
            'ltc' => 'litecoin',
            'dash' => 'dash',
            default => strtolower($invoice->coin)
        };

        $query = http_build_query([
            'amount' => (string)$invoice->amount_coin,
            'label' => 'Invoice ' . $invoice->public_id,
        ]);

        return "{$scheme}:{$invoice->pay_address}?{$query}";
    }

    private function paymentMode(Invoice $invoice): ?string
    {
        $assetKey = $invoice->asset_key ?: $invoice->coin;
        $networkKey = $invoice->network_key;

        if (!$assetKey || !$networkKey) {
            return null;
        }

        try {
            $asset = app(AssetRegistry::class)->get($assetKey);
            $family = app(ChainRegistry::class)->family($networkKey);
        } catch (RuntimeException) {
            return null;
        }

        if ($family !== 'evm') {
            return 'utxo';
        }

        return (($asset['type'] ?? null) === 'token') ? 'evm_token' : 'evm_native';
    }
}
