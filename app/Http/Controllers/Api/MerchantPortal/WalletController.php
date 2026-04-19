<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $wallets = SuperWallet::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->orderBy('coin')
            ->get()
            ->map(fn(SuperWallet $wallet) => [
                'id' => $wallet->id,
                'coin' => strtoupper($wallet->coin),
                'asset_key' => $wallet->asset_key ?: strtolower((string) $wallet->coin),
                'network_key' => $wallet->network_key,
                'wallet' => $wallet->wallet,
                'fee_rate' => $wallet->fee_rate !== null ? (string)$wallet->fee_rate : null,
                'created_at' => optional($wallet->created_at)->toIso8601String(),
                'updated_at' => optional($wallet->updated_at)->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $wallets,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $data = $request->validate([
            'coin' => ['required', 'string', Rule::in(app(AssetRegistry::class)->keys())],
            'wallet' => 'required|string|max:255',
            'fee_rate' => 'nullable|numeric|min:0',
        ]);

        $assetKey = strtolower($data['coin']);
        $asset = app(AssetRegistry::class)->get($assetKey);
        $networkKey = (string) $asset['network'];

        $wallet = SuperWallet::query()->updateOrCreate(
            [
                'merchant_id' => $merchantUser->merchant_id,
                'coin' => $assetKey,
            ],
            [
                'coin' => $assetKey,
                'asset_key' => $assetKey,
                'network_key' => $networkKey,
                'wallet' => $data['wallet'],
                'fee_rate' => $data['fee_rate'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $wallet->id,
                'coin' => strtoupper($wallet->coin),
                'asset_key' => $wallet->asset_key,
                'network_key' => $wallet->network_key,
                'wallet' => $wallet->wallet,
                'fee_rate' => $wallet->fee_rate !== null ? (string)$wallet->fee_rate : null,
            ]
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $wallet = SuperWallet::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->findOrFail($id);

        $data = $request->validate([
            'wallet' => 'required|string|max:255',
            'fee_rate' => 'nullable|numeric|min:0',
        ]);

        $wallet->update([
            'wallet' => $data['wallet'],
            'fee_rate' => $data['fee_rate'] ?? null,
        ]);

        $assets = app(AssetRegistry::class);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $wallet->id,
                'coin' => strtoupper($wallet->coin),
                'coin_symbol' => $assets->symbol($wallet->coin),
                'asset_key' => $wallet->asset_key ?: strtolower((string) $wallet->coin),
                'network_key' => $wallet->network_key,
                'wallet' => $wallet->wallet,
                'fee_rate' => $wallet->fee_rate !== null ? (string)$wallet->fee_rate : null,
            ]
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $wallet = SuperWallet::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->findOrFail($id);

        $wallet->delete();

        return response()->json(['success' => true]);
    }
}
