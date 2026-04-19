<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantBalance;
use App\Models\MerchantUser;
use App\Support\Assets\AssetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BalanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $assets = app(AssetRegistry::class);

        $balances = MerchantBalance::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->orderBy('coin')
            ->get()
            ->map(function (MerchantBalance $balance) use ($assets): array {
                $assetKey = strtolower((string) $balance->coin);
                $networkKey = null;

                try {
                    $networkKey = $assets->network($assetKey);
                } catch (RuntimeException) {
                    // Preserve legacy balances even if asset catalog no longer has this key.
                }

                return [
                    'id' => $balance->id,
                    'coin' => strtoupper($balance->coin),
                    'asset_key' => $assetKey,
                    'network_key' => $networkKey,
                    'amount' => (string) $balance->amount,
                    'created_at' => optional($balance->created_at)->toIso8601String(),
                    'updated_at' => optional($balance->updated_at)->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $balances,
        ]);
    }
}
