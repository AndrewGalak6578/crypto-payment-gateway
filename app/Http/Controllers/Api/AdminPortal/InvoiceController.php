<?php

namespace App\Http\Controllers\Api\AdminPortal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    //
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()
            ->with('merchant')
            ->latest('id');

        if ($merchantId = $request->query('merchant_id')) {
            $query->where('merchant_id', (int) $merchantId);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function (Builder $q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }

                $q->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($coin = $request->query('coin')) {
            $query->where('coin', strtolower((string) $coin));
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $invoices = $query->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $invoices->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'merchant_id' => $invoice->merchant_id,
                'merchant_name' => $invoice->merchant?->name,
                'status' => $invoice->status,
                'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
                'amount_coin' => $invoice->amount_coin !== null ? (string) $invoice->amount_coin : null,
                'expected_usd' => $invoice->expected_usd !== null ? (string) $invoice->expected_usd : null,
                'received_conf_coin' => $invoice->received_conf_coin !== null ? (string) $invoice->received_conf_coin : null,
                'forward_status' => $invoice->forward_status,
                'created_at' => optional($invoice->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['merchant', 'webhookDeliveries']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $invoice->id,
                'merchant' => [
                    'id' => $invoice->merchant?->id,
                    'name' => $invoice->merchant?->name,
                    'status' => $invoice->merchant?->status,
                ],
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
                'pay_address' => $invoice->pay_address,
                'amount_coin' => $invoice->amount_coin !== null ? (string) $invoice->amount_coin : null,
                'expected_usd' => $invoice->expected_usd !== null ? (string) $invoice->expected_usd : null,
                'rate_usd' => $invoice->rate_usd !== null ? (string) $invoice->rate_usd : null,
                'received_conf_coin' => $invoice->received_conf_coin !== null ? (string) $invoice->received_conf_coin : null,
                'received_all_coin' => $invoice->received_all_coin !== null ? (string) $invoice->received_all_coin : null,
                'paid_usd' => $invoice->paid_usd !== null ? (string) $invoice->paid_usd : null,
                'fee_coin' => $invoice->fee_coin !== null ? (string) $invoice->fee_coin : null,
                'merchant_payout_coin' => $invoice->merchant_payout_coin !== null ? (string) $invoice->merchant_payout_coin : null,
                'fee_usd' => $invoice->fee_usd !== null ? (string) $invoice->fee_usd : null,
                'merchant_payout_usd' => $invoice->merchant_payout_usd !== null ? (string) $invoice->merchant_payout_usd : null,
                'forward_status' => $invoice->forward_status,
                'forwarded_coin' => $invoice->forwarded_coin !== null ? (string) $invoice->forwarded_coin : null,
                'forwarding_coin' => $invoice->forwarding_coin !== null ? (string) $invoice->forwarding_coin : null,
                'forward_txids' => $invoice->forward_txids ?? [],
                'first_txid' => $invoice->first_txid,
                'first_amount_coin' => $invoice->first_amount_coin !== null ? (string) $invoice->first_amount_coin : null,
                'expires_at' => optional($invoice->expires_at)->toIso8601String(),
                'fixated_at' => optional($invoice->fixated_at)->toIso8601String(),
                'paid_at' => optional($invoice->paid_at)->toIso8601String(),
                'created_at' => optional($invoice->created_at)->toIso8601String(),
                'metadata' => $invoice->metadata ?? [],
                'webhook_deliveries' => $invoice->webhookDeliveries->map(fn ($delivery) => [
                    'id' => $delivery->id,
                    'event' => $delivery->event,
                    'status' => $delivery->status,
                    'attempts' => $delivery->attempts,
                    'created_at' => optional($delivery->created_at)->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function refresh(Invoice $invoice, InvoiceStatusRefresher $refresher): JsonResponse
    {
        $invoice = $refresher->refresh($invoice);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'status' => $invoice->status,
                'received_conf_coin' => $invoice->received_conf_coin !== null ? (string) $invoice->received_conf_coin : null,
                'received_all_coin' => $invoice->received_all_coin !== null ? (string) $invoice->received_all_coin : null,
                'forward_status' => $invoice->forward_status,
                'updated_at' => optional($invoice->updated_at)->toIso8601String(),
            ],
        ]);
    }
}
