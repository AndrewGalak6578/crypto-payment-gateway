<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantSettlementEntry;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'type' => ['nullable', 'string', 'max:32'],
            'asset' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $entries = MerchantSettlementEntry::query()
            ->with('invoice:id,public_id,external_id')
            ->where('merchant_id', $merchantUser->merchant_id)
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($data['asset'] ?? null, fn ($query, string $asset) => $query->where('asset_key', strtolower($asset)))
            ->when($data['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner
                        ->where('txid', 'like', "%{$search}%")
                        ->orWhere('destination_wallet', 'like', "%{$search}%")
                        ->orWhereHas('invoice', function ($invoiceQuery) use ($search): void {
                            $invoiceQuery
                                ->where('public_id', 'like', "%{$search}%")
                                ->orWhere('external_id', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 12));

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $entries->getCollection()->map(fn (MerchantSettlementEntry $entry): array => [
                    'id' => $entry->id,
                    'type' => $entry->type,
                    'status' => $entry->status,
                    'asset_key' => $entry->asset_key,
                    'network_key' => $entry->network_key,
                    'amount_coin' => (string) $entry->amount_coin,
                    'fee_coin' => $entry->fee_coin !== null ? (string) $entry->fee_coin : null,
                    'amount_usd' => $entry->amount_usd !== null ? (string) $entry->amount_usd : null,
                    'destination_wallet' => $entry->destination_wallet,
                    'txid' => $entry->txid,
                    'txids' => $this->txids($entry),
                    'error_message' => $entry->error_message,
                    'occurred_at' => optional($entry->occurred_at)->toIso8601String(),
                    'created_at' => optional($entry->created_at)->toIso8601String(),
                    'invoice' => $entry->invoice ? [
                        'id' => $entry->invoice->id,
                        'public_id' => $entry->invoice->public_id,
                        'external_id' => $entry->invoice->external_id,
                    ] : null,
                ])->values(),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function txids(MerchantSettlementEntry $entry): array
    {
        if (is_string($entry->txid) && $entry->txid !== '') {
            return [$entry->txid];
        }

        $txids = $entry->metadata['forward_txids'] ?? [];
        if (! is_array($txids)) {
            return [];
        }

        return array_values(array_filter($txids, fn ($txid) => is_string($txid) && $txid !== ''));
    }
}
