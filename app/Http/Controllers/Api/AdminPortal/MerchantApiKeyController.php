<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\AdminPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantApiKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MerchantApiKey::query()
            ->with('merchant')
            ->latest('id');

        if ($merchantId = $request->query('merchant_id')) {
            $query->where('merchant_id', (int) $merchantId);
        }

        if ($revoked = $request->query('revoked')) {
            if ($revoked === '1') {
                $query->whereNotNull('revoked_at');
            }

            if ($revoked === '0') {
                $query->whereNull('revoked_at');
            }
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function (Builder $q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search)
                        ->orWhere('merchant_id', (int) $search);
                }

                $q->orWhere('name', 'like', "%{$search}%");
            });
        }

        $keys = $query->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $keys->through(fn (MerchantApiKey $key) => [
                'id' => $key->id,
                'merchant_id' => $key->merchant_id,
                'merchant_name' => $key->merchant?->name,
                'name' => $key->name,
                'last_used_at' => optional($key->last_used_at)->toIso8601String(),
                'revoked_at' => optional($key->revoked_at)->toIso8601String(),
                'created_at' => optional($key->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $keys->currentPage(),
                'last_page' => $keys->lastPage(),
                'per_page' => $keys->perPage(),
                'total' => $keys->total(),
            ],
        ]);
    }

    public function revoke(MerchantApiKey $apiKey): JsonResponse
    {
        if (!$apiKey->revoked_at) {
            $apiKey->update([
                'revoked_at' => now('UTC'),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $apiKey->id,
                'revoked_at' => optional($apiKey->revoked_at)->toIso8601String(),
            ],
        ]);
    }
}
