<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvoiceRequest;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Services\InvoiceCreator;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Merchant API endpoints for invoice creation and retrieval.
 */
class InvoiceController extends Controller
{
    /**
     * Creates a new invoice or returns existing one by external_id.
     *
     * @param CreateInvoiceRequest $request
     * @param InvoiceCreator $creator
     */
    public function store(CreateInvoiceRequest $request, InvoiceCreator $creator): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $invoice = $creator->create($merchant, $request->validated());

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => strtoupper($invoice->coin),
                'asset_key' => $invoice->asset_key,
                'network_key' => $invoice->network_key,
                'pay_address' => $invoice->pay_address,
                'amount_coin' => (string)$invoice->amount_coin,
                'expected_usd' => (string)$invoice->expected_usd,
                'rate_usd' => (string)$invoice->rate_usd,
                'expires_at' => optional($invoice->expires_at)->toIso8601String(),
                'hosted_url' => route('hosted-invoice.show', ['publicId' => $invoice->public_id]),
            ],
        ], 201);
    }

    /**
     * Returns invoice details and optionally refreshes chain state.
     *
     * @param Request $request
     * @param int $id Internal invoice identifier.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');


        $invoice = Invoice::where('merchant_id', $merchant->id)->findOrFail($id);

        if ($request->boolean('refresh')) {
            /** @var InvoiceStatusRefresher $refresher */
            $refresher = app(InvoiceStatusRefresher::class);
            $invoice = $refresher->refresh($invoice);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => strtoupper($invoice->coin),
                'asset_key' => $invoice->asset_key,
                'network_key' => $invoice->network_key,
                'pay_address' => $invoice->pay_address,
                'amount_coin' => (string)$invoice->amount_coin,
                'expected_usd' => (string)$invoice->expected_usd,
                'rate_usd' => (string)$invoice->rate_usd,
                'received_conf_coin' => (string)$invoice->received_conf_coin,
                'received_all_coin' => (string)$invoice->received_all_coin,
                'expires_at' => optional($invoice->expires_at)->toIso8601String(),
                'fixated_at' => optional($invoice->fixated_at)->toIso8601String(),
                'paid_at' => optional($invoice->paid_at)->toIso8601String(),
            ],
        ]);
    }
}
