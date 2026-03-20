<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantApiKey;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $keys = MerchantApiKey::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->latest('id')
            ->get()
            ->map(fn(MerchantApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'last_used_at' => optional($key->last_used_at)->toIso8601String(),
                'revoked_at' => optional($key->revoked_at)->toIso8601String(),
                'created_at' => $key->created_at->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $keys,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $plainToken = 'mapi_' . Str::random(40);

        $key = MerchantApiKey::query()->create([
            'merchant_id' => $merchantUser->merchant_id,
            'name' => $data['name'],
            'token_hash' => hash('sha256', $plainToken),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $key->id,
                'name' => $key->name,
                'token' => $plainToken,
                'created_at' => optional($key->created_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $key = MerchantApiKey::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->findOrFail($id);

        $key->update([
            'revoked_at' => now('UTC'),
        ]);

        return response()->json([
            'success' => true,
        ]);
    }
}
