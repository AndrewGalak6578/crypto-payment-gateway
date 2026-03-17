<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HostedInvoiceController extends Controller
{
    public function show(string $publicId): View
    {
        $invoice = Invoice::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        return view('hosted-invoices.show', [
            'invoice' => $invoice,
            'paymentUri' => $this->paymentUri($invoice),
            'statusUrl' => route('hosted-invoice.status', ['publicId' => $invoice->public_id])
        ]);
    }

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
}
