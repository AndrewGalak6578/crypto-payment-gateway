<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Http\Request;

class InvoiceRefreshController extends Controller
{
    public function __invoke(Request $request, int $id, InvoiceStatusRefresher $refresher)
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $invoice = Invoice::query()
            ->where('merchant_id', $merchant->id)
            ->where('public_id', $id)
            ->firstOrFail();

        $invoice = $refresher->refresh($invoice);

        return response()->json(['success' => true, 'data' => $invoice]);
    }
}
