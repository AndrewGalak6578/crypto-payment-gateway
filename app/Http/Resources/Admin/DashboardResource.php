<?php

namespace App\Http\Resources\Admin;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'stats' => [
                'merchants_total' => Merchant::query()->count(),
                'merchants_active' => Merchant::query()->where('status', 'active')->count(),
                'merchants_disabled' => Merchant::query()->where('status', 'disabled')->count(),
                'invoices_total' => Invoice::query()->count(),
                'failed_webhook_deliveries' => WebhookDelivery::query()
                    ->where('status', 'failed')
                    ->count(),
            ],
        ];
    }
}
