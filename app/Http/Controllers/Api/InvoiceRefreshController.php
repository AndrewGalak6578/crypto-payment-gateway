<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dedicated endpoint for forcing invoice state refresh by public_id.
 */
class InvoiceRefreshController extends Controller
{
    /**
     * @param Request $request
     * @param int $id Public invoice identifier.
     * @param InvoiceStatusRefresher $refresher
     */
    public function __invoke(Request $request, int $id, InvoiceStatusRefresher $refresher): JsonResponse
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
