<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->findOrFail($merchantUser->merchant_id);

        return response()->json([
            'success' => true,
            'data' => [
                'webhook_url' => $merchant->webhook_url,
                'has_webhook_secret' => !empty($merchant->webhook_secret),
            ]
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->findOrFail($merchantUser->merchant_id);

        $data = $request->validate([
            'webhook_url' => 'nullable|url|max:1000',
            'webhook_secret' => 'nullable|string|max:255',
        ]);

        $merchant->webhook_url = $data['webhook_url'] ?? null;

        if (array_key_exists('webhook_secret', $data)) {
            $merchant->webhook_secret = $data['webhook_secret'];
        }

        $merchant->save();

        return response()->json([
            'success' => true,
            'data' => [
                'webhook_url' => $merchant->webhook_url,
                'has_webhook_secret' => !empty($merchant->webhook_secret),
            ],
        ]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $deliveries = WebhookDelivery::query()
            ->whereHas('invoice', function ($q) use ($merchantUser) {
                $q->where('merchant_id', $merchantUser->merchant_id);
            })
            ->latest('id')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $deliveries->through(fn (WebhookDelivery $delivery) => $this->serializeListItem($delivery)),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
            ],
        ]);
    }

    public function deliveryDetail(Request $request, int $delivery): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $webhookDelivery = WebhookDelivery::query()
            ->whereKey($delivery)
            ->whereHas('invoice', fn (Builder $query) => $query->where('merchant_id', $merchantUser->merchant_id))
            ->firstOrFail();

        $payload = $webhookDelivery->payload;
        $payloadPreview = null;

        if ($payload !== null) {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($jsonPayload)) {
                $payloadPreview = mb_substr($jsonPayload, 0, 2000);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $webhookDelivery->id,
                'invoice_id' => $webhookDelivery->invoice_id,
                'event' => $webhookDelivery->event,
                'status' => $webhookDelivery->status,
                'attempts' => $webhookDelivery->attempts,
                'url' => $webhookDelivery->url,
                'last_error' => $webhookDelivery->last_error,
                'delivered_at' => optional($webhookDelivery->delivered_at)->toIso8601String(),
                'created_at' => optional($webhookDelivery->created_at)->toIso8601String(),
                'updated_at' => optional($webhookDelivery->updated_at)->toIso8601String(),
                'next_retry_at' => optional($webhookDelivery->next_retry_at)->toIso8601String(),
                'payload' => $payload,
                'payload_preview' => $payloadPreview,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListItem(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'invoice_id' => $delivery->invoice_id,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'url' => $delivery->url,
            'last_error' => $delivery->last_error,
            'delivered_at' => optional($delivery->delivered_at)->toIso8601String(),
            'created_at' => optional($delivery->created_at)->toIso8601String(),
        ];
    }
}
