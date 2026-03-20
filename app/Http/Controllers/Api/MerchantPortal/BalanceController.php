<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantBalance;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $balances = MerchantBalance::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->orderBy('coin')
            ->get()
            ->map(fn(MerchantBalance $balance) => [
                'id' => $balance->id,
                'coin' => strtoupper($balance->coin),
                'amount' => (string) $balance->amount,
                'created_at' => optional($balance->created_at)->toIso8601String(),
                'updated_at' => optional($balance->updated_at)->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $balances,
        ]);
    }
}
