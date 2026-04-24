<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvoiceRequest;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Services\InvoiceCreator;
use App\Services\InvoiceStatusRefresher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function store(CreateInvoiceRequest $request, InvoiceCreator $creator): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->findOrFail($merchantUser->merchant_id);

        $invoice = $creator->create($merchant, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->serializeCreateInvoice($invoice),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $query = Invoice::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->latest('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('public_id', 'like', "%{$search}%")
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

        $invoices = $query->paginate((int)$request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $invoices->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => strtoupper($invoice->coin),
                'asset_key' => $invoice->asset_key,
                'network_key' => $invoice->network_key,
                'amount_coin' => (string) $invoice->amount_coin,
                'expected_usd' => (string) $invoice->expected_usd,
                'received_conf_coin' => (string) $invoice->received_conf_coin,
                'forward_status' => $invoice->forward_status,
                'created_at' => $invoice->created_at->toIso8601String(),
                'hosted_url' => $this->hostedUrl($invoice),
            ]),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ]
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $invoice = Invoice::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->serializeInvoiceDetail($invoice),
        ]);
    }

    public function refresh(
        Request $request,
        int $id,
        InvoiceStatusRefresher $refresher
    ): JsonResponse {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $invoice = Invoice::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->findOrFail($id);

        $invoice = $refresher->refresh($invoice);

        return response()->json([
            'success' => true,
            'data' => $this->serializeInvoiceDetail($invoice),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCreateInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'public_id' => $invoice->public_id,
            'external_id' => $invoice->external_id,
            'status' => $invoice->status,
            'coin' => strtoupper($invoice->coin),
            'asset_key' => $invoice->asset_key,
            'network_key' => $invoice->network_key,
            'pay_address' => $invoice->pay_address,
            'amount_coin' => (string) $invoice->amount_coin,
            'expected_usd' => (string) $invoice->expected_usd,
            'rate_usd' => (string) $invoice->rate_usd,
            'expires_at' => optional($invoice->expires_at)->toIso8601String(),
            'hosted_url' => $this->hostedUrl($invoice),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInvoiceDetail(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'public_id' => $invoice->public_id,
            'external_id' => $invoice->external_id,
            'status' => $invoice->status,
            'coin' => strtoupper($invoice->coin),
            'asset_key' => $invoice->asset_key,
            'network_key' => $invoice->network_key,
            'pay_address' => $invoice->pay_address,
            'amount_coin' => (string) $invoice->amount_coin,
            'expected_usd' => (string) $invoice->expected_usd,
            'rate_usd' => (string) $invoice->rate_usd,
            'received_conf_coin' => (string) $invoice->received_conf_coin,
            'received_all_coin' => (string) $invoice->received_all_coin,
            'paid_usd' => $invoice->paid_usd !== null ? (string) $invoice->paid_usd : null,
            'fee_coin' => $invoice->fee_coin !== null ? (string) $invoice->fee_coin : null,
            'merchant_payout_coin' => $invoice->merchant_payout_coin !== null ? (string) $invoice->merchant_payout_coin : null,
            'fee_usd' => $invoice->fee_usd !== null ? (string) $invoice->fee_usd : null,
            'merchant_payout_usd' => $invoice->merchant_payout_usd !== null ? (string) $invoice->merchant_payout_usd : null,
            'forward_status' => $invoice->forward_status,
            'forwarded_coin' => $invoice->forwarded_coin !== null ? (string) $invoice->forwarded_coin : null,
            'forward_txids' => $invoice->forward_txids ?? [],
            'expires_at' => optional($invoice->expires_at)->toIso8601String(),
            'fixated_at' => optional($invoice->fixated_at)->toIso8601String(),
            'paid_at' => optional($invoice->paid_at)->toIso8601String(),
            'created_at' => optional($invoice->created_at)->toIso8601String(),
            'metadata' => $invoice->metadata ?? [],
            'hosted_url' => $this->hostedUrl($invoice),
        ];
    }

    private function hostedUrl(Invoice $invoice): string
    {
        return route('hosted-invoice.show', ['publicId' => $invoice->public_id]);
    }
}
