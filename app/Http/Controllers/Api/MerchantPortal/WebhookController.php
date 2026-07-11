<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\WebhookDelivery;
use App\Rules\PublicWebhookUrl;
use App\Services\MerchantActivityLogger;
use App\Services\Webhooks\SendMerchantTestWebhook;
use App\Services\Webhooks\WebhookDeliveryRetryer;
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
                'has_webhook_secret' => ! empty($merchant->webhook_secret),
            ],
        ]);
    }

    public function updateSettings(Request $request, MerchantActivityLogger $activity): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->findOrFail($merchantUser->merchant_id);

        $data = $request->validate([
            'webhook_url' => ['nullable', 'url', 'max:1000', new PublicWebhookUrl],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $merchant->webhook_url = $data['webhook_url'] ?? null;

        if (array_key_exists('webhook_secret', $data)) {
            $merchant->webhook_secret = $data['webhook_secret'];
        }

        $merchant->save();

        $activity->log($request, 'developers', 'webhook_settings.updated', [
            'webhook_url_changed' => array_key_exists('webhook_url', $data),
            'webhook_secret_changed' => array_key_exists('webhook_secret', $data),
            'has_webhook_url' => ! empty($merchant->webhook_url),
        ], [
            'type' => 'security',
            'target_type' => Merchant::class,
            'target_id' => $merchant->id,
            'target_label' => $merchant->name,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'webhook_url' => $merchant->webhook_url,
                'has_webhook_secret' => ! empty($merchant->webhook_secret),
            ],
        ]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $deliveries = WebhookDelivery::query()
            ->where(fn (Builder $query) => $this->scopeMerchantDeliveries($query, (int) $merchantUser->merchant_id))
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
            ->where(fn (Builder $query) => $this->scopeMerchantDeliveries($query, (int) $merchantUser->merchant_id))
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

    public function retryDelivery(Request $request, int $delivery, WebhookDeliveryRetryer $retryer, MerchantActivityLogger $activity): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $webhookDelivery = WebhookDelivery::query()
            ->whereKey($delivery)
            ->where(fn (Builder $query) => $this->scopeMerchantDeliveries($query, (int) $merchantUser->merchant_id))
            ->firstOrFail();

        $queued = $retryer->retry($webhookDelivery);
        $webhookDelivery->refresh();

        $activity->log($request, 'developers', 'webhook_delivery.retried', [
            'delivery_id' => $webhookDelivery->id,
            'event' => $webhookDelivery->event,
            'status' => $webhookDelivery->status,
            'queued' => $queued,
        ], [
            'type' => 'action',
            'target_type' => WebhookDelivery::class,
            'target_id' => $webhookDelivery->id,
            'target_label' => $webhookDelivery->event,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $webhookDelivery->id,
                'status' => $webhookDelivery->status,
                'queued' => $queued,
            ],
        ]);
    }

    public function sendTest(Request $request, SendMerchantTestWebhook $testWebhook, MerchantActivityLogger $activity): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->findOrFail($merchantUser->merchant_id);

        $delivery = $testWebhook->send($merchant);

        $activity->log($request, 'developers', 'webhook_test.sent', [
            'delivery_id' => $delivery->id,
            'event' => $delivery->event,
            'url' => $delivery->url,
        ], [
            'type' => 'action',
            'target_type' => WebhookDelivery::class,
            'target_id' => $delivery->id,
            'target_label' => $delivery->event,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'url' => $delivery->url,
                'created_at' => optional($delivery->created_at)->toIso8601String(),
            ],
        ], 202);
    }

    private function scopeMerchantDeliveries(Builder $query, int $merchantId): void
    {
        $query
            ->where('merchant_id', $merchantId)
            ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('merchant_id', $merchantId));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListItem(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'merchant_id' => $delivery->merchant_id,
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
