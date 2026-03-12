<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvoiceRequest;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Services\InvoiceCreator;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function store(CreateInvoiceRequest $request, InvoiceCreator $creator)
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
                'pay_address' => $invoice->pay_address,
                'amount_coin' => (string)$invoice->amount_coin,
                'expected_usd' => (string)$invoice->expected_usd,
                'rate_usd' => (string)$invoice->rate_usd,
                'expires_at' => optional($invoice->expires_at)->toIso8601String(),
                'hosted_url' => rtrim(config('app_url'), '/') . '/i/' . $invoice->public_id,
            ],
        ], 201);
    }

    public function show(Request $request, int $id)
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
