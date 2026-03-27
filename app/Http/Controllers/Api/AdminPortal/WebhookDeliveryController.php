<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\AdminPortal;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookDeliveryController extends Controller
{
    //
    public function index(Request $request): JsonResponse
    {
        $query = WebhookDelivery::query()
            ->with('invoice.merchant')
            ->latest('id');

        if ($merchantId = $request->query('merchant_id')) {
            $query->whereHas('invoice', fn (Builder $q) => $q->where('merchant_id', (int) $merchantId));
        }

        if ($invoiceId = $request->query('invoice_id')) {
            $query->where('invoice_id', (int) $invoiceId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($event = $request->query('event')) {
            $query->where('event', $event);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function (Builder $q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search)
                        ->orWhere('invoice_id', (int) $search);
                }

                $q->orWhere('url', 'like', "%{$search}%");
            });
        }

        $deliveries = $query->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $deliveries->through(fn (WebhookDelivery $delivery) => [
                'id' => $delivery->id,
                'invoice_id' => $delivery->invoice_id,
                'merchant_id' => $delivery->invoice?->merchant?->id,
                'merchant_name' => $delivery->invoice?->merchant?->name,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'url' => $delivery->url,
                'last_error' => $delivery->last_error,
                'delivered_at' => optional($delivery->delivered_at)->toIso8601String(),
                'created_at' => optional($delivery->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
            ],
        ]);
    }

    public function show(WebhookDelivery $delivery): JsonResponse
    {
        $delivery->load('invoice.merchant');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $delivery->id,
                'invoice' => [
                    'id' => $delivery->invoice?->id,
                    'public_id' => $delivery->invoice?->public_id,
                    'merchant_id' => $delivery->invoice?->merchant?->id,
                    'merchant_name' => $delivery->invoice?->merchant?->name,
                ],
                'event' => $delivery->event,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'url' => $delivery->url,
                'payload' => $delivery->payload,
                'signature' => $delivery->signature,
                'last_error' => $delivery->last_error,
                'next_retry_at' => optional($delivery->next_retry_at)->toIso8601String(),
                'delivered_at' => optional($delivery->delivered_at)->toIso8601String(),
                'created_at' => optional($delivery->created_at)->toIso8601String(),
                'updated_at' => optional($delivery->updated_at)->toIso8601String(),
            ],
        ]);
    }

    public function retry(WebhookDelivery $delivery): JsonResponse
    {
        DeliverWebhookJob::dispatch($delivery->id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $delivery->id,
                'status' => $delivery->status,
                'queued' => true,
            ],
        ]);
    }
}
