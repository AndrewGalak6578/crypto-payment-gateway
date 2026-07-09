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
use App\Services\MerchantPortalAccess;
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

        $data = $this->applyMerchantDefaults($merchant, $request->validated());
        if ($guard = $this->validateAgainstMerchantSettings($merchant, $data)) {
            return $guard;
        }

        $invoice = $creator->create($merchant, $data);

        return response()->json([
            'success' => true,
            'data' => $this->serializeCreateInvoice($invoice),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $query = $this->invoiceListQuery($request, $merchantUser->merchant_id)
            ->latest('id');

        $invoices = $query->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => $invoices->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
                'asset_key' => $invoice->asset_key,
                'network_key' => $invoice->network_key,
                'amount_coin' => (string) $invoice->amount_coin,
                'expected_usd' => (string) $invoice->expected_usd,
                'received_conf_coin' => (string) $invoice->received_conf_coin,
                'received_all_coin' => (string) $invoice->received_all_coin,
                'forward_status' => $invoice->forward_status,
                'created_at' => $invoice->created_at->toIso8601String(),
                'hosted_url' => $this->hostedUrl($invoice),
            ]),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $query = $this->invoiceListQuery($request, $merchantUser->merchant_id, applyStatus: false);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => (clone $query)->count(),
                'paid' => (clone $query)->where('status', 'paid')->count(),
                'awaiting_asset' => (clone $query)->where('status', 'awaiting_asset')->count(),
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'confirming' => (clone $query)->where('status', 'fixated')->count(),
                'expired' => (clone $query)->where('status', 'expired')->count(),
                'partial' => (clone $query)
                    ->whereIn('status', ['pending', 'fixated'])
                    ->where('received_all_coin', '>', 0)
                    ->whereColumn('received_all_coin', '<', 'amount_coin')
                    ->count(),
                'underpaid' => (clone $query)
                    ->whereIn('status', ['pending', 'fixated'])
                    ->where('received_all_coin', '>', 0)
                    ->whereColumn('received_all_coin', '<', 'amount_coin')
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, int|string $id, MerchantPortalAccess $access): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');
        $includeWebhooks = $access->can($merchantUser, 'webhooks.read');

        $query = Invoice::query()
            ->when($includeWebhooks, fn (Builder $query) => $query->with([
                'webhookDeliveries' => fn ($query) => $query->latest('id')->limit(5),
            ]))
            ->where('merchant_id', $merchantUser->merchant_id);

        $this->whereInvoiceIdentifier($query, $id);
        $invoice = $query->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->serializeInvoiceDetail($invoice, $includeWebhooks),
        ]);
    }

    public function refresh(
        Request $request,
        int|string $id,
        InvoiceStatusRefresher $refresher,
        MerchantPortalAccess $access
    ): JsonResponse {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');
        $includeWebhooks = $access->can($merchantUser, 'webhooks.read');

        $query = Invoice::query()
            ->where('merchant_id', $merchantUser->merchant_id);

        $this->whereInvoiceIdentifier($query, $id);
        $invoice = $query->firstOrFail();

        $invoice = $refresher->refresh($invoice);
        if ($includeWebhooks) {
            $invoice->load(['webhookDeliveries' => fn ($query) => $query->latest('id')->limit(5)]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeInvoiceDetail($invoice, $includeWebhooks),
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
            'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
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

    private function applyMerchantDefaults(Merchant $merchant, array $data): array
    {
        if (! array_key_exists('expires_minutes', $data) && $merchant->checkout_expires_minutes) {
            $data['expires_minutes'] = $merchant->checkout_expires_minutes;
        }

        if (! array_key_exists('coin', $data) && ! $merchant->checkout_payer_can_choose_asset && $merchant->checkout_default_asset) {
            $data['coin'] = $merchant->checkout_default_asset;
        }

        $metadata = $data['metadata'] ?? [];
        $redirects = $metadata['redirects'] ?? [];

        if (! isset($redirects['success_url']) && $merchant->checkout_success_url) {
            $redirects['success_url'] = $merchant->checkout_success_url;
        }

        if (! isset($redirects['cancel_url']) && $merchant->checkout_cancel_url) {
            $redirects['cancel_url'] = $merchant->checkout_cancel_url;
        }

        if ($redirects !== []) {
            $metadata['redirects'] = $redirects;
            $data['metadata'] = $metadata;
        }

        return $data;
    }

    private function validateAgainstMerchantSettings(Merchant $merchant, array $data): ?JsonResponse
    {
        $amountUsd = (float) ($data['amount_usd'] ?? 0);

        if ($merchant->checkout_min_amount_usd !== null && $amountUsd < (float) $merchant->checkout_min_amount_usd) {
            return response()->json([
                'success' => false,
                'message' => 'Amount is below the merchant minimum.',
                'errors' => [
                    'amount_usd' => ['Amount is below the merchant minimum.'],
                ],
            ], 422);
        }

        if ($merchant->checkout_max_amount_usd !== null && $amountUsd > (float) $merchant->checkout_max_amount_usd) {
            return response()->json([
                'success' => false,
                'message' => 'Amount is above the merchant maximum.',
                'errors' => [
                    'amount_usd' => ['Amount is above the merchant maximum.'],
                ],
            ], 422);
        }

        $allowedAssets = $merchant->checkout_allowed_assets ?? [];
        $asset = $data['coin'] ?? null;
        if ($asset && $allowedAssets !== [] && ! in_array($asset, $allowedAssets, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Selected asset is not allowed by merchant settings.',
                'errors' => [
                    'coin' => ['Selected asset is not allowed by merchant settings.'],
                ],
            ], 422);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInvoiceDetail(Invoice $invoice, bool $includeWebhooks = false): array
    {
        $data = [
            'id' => $invoice->id,
            'public_id' => $invoice->public_id,
            'external_id' => $invoice->external_id,
            'status' => $invoice->status,
            'coin' => $invoice->coin ? strtoupper($invoice->coin) : null,
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
            'forwarding_coin' => $invoice->forwarding_coin !== null ? (string) $invoice->forwarding_coin : null,
            'forward_txids' => $invoice->forward_txids ?? [],
            'first_txid' => $invoice->first_txid,
            'first_amount_coin' => $invoice->first_amount_coin !== null ? (string) $invoice->first_amount_coin : null,
            'expires_at' => optional($invoice->expires_at)->toIso8601String(),
            'fixated_at' => optional($invoice->fixated_at)->toIso8601String(),
            'paid_at' => optional($invoice->paid_at)->toIso8601String(),
            'forwarding_started_at' => optional($invoice->forwarding_started_at)->toIso8601String(),
            'last_forwarded_at' => optional($invoice->last_forwarded_at)->toIso8601String(),
            'created_at' => optional($invoice->created_at)->toIso8601String(),
            'metadata' => $invoice->metadata ?? [],
            'hosted_url' => $this->hostedUrl($invoice),
        ];

        if ($includeWebhooks) {
            $data['webhook_deliveries'] = $invoice->webhookDeliveries->map(fn ($delivery) => [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'url' => $delivery->url,
                'last_error' => $delivery->last_error,
                'delivered_at' => optional($delivery->delivered_at)->toIso8601String(),
                'created_at' => optional($delivery->created_at)->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }

    private function hostedUrl(Invoice $invoice): string
    {
        return route('hosted-invoice.show', ['publicId' => $invoice->public_id]);
    }

    private function whereInvoiceIdentifier(Builder $query, int|string $identifier): void
    {
        $identifier = (string) $identifier;

        if (ctype_digit($identifier)) {
            $query->where('id', (int) $identifier);
            return;
        }

        $query->where('public_id', $identifier);
    }

    private function invoiceListQuery(Request $request, int $merchantId, bool $applyStatus = true): Builder
    {
        $query = Invoice::query()->where('merchant_id', $merchantId);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('public_id', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        if ($applyStatus && ($status = $request->query('status'))) {
            $status = strtolower((string) $status);

            if ($status === 'awaiting') {
                $query->whereIn('status', ['awaiting_asset', 'pending']);
            } elseif ($status === 'partial') {
                $query->whereIn('status', ['pending', 'fixated'])
                    ->where('received_all_coin', '>', 0)
                    ->whereColumn('received_all_coin', '<', 'amount_coin');
            } else {
                $query->where('status', $status);
            }
        }

        if ($coin = $request->query('coin')) {
            $coins = collect(explode(',', strtolower((string) $coin)))
                ->map(fn (string $coin): string => trim($coin))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($coins !== []) {
                $query->where(function (Builder $query) use ($coins): void {
                    $query->whereIn('coin', $coins)
                        ->orWhereIn('asset_key', $coins);
                });
            }
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 15);

        return in_array($perPage, [15, 25, 50, 100], true) ? $perPage : 15;
    }
}
