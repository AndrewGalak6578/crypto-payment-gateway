<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\AdminPortal;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\SuperWallet;
use App\Support\Assets\AssetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchantWalletController extends Controller
{
    public function index(Merchant $merchant): JsonResponse
    {
        $wallets = $merchant->superWallets()
            ->orderBy('coin')
            ->get()
            ->map(fn (SuperWallet $wallet) => $this->walletPayload($wallet));

        return response()->json([
            'success' => true,
            'data' => $wallets,
        ]);
    }

    public function store(Request $request, Merchant $merchant): JsonResponse
    {
        $data = $request->validate([
            'coin' => ['required', 'string', Rule::in(app(AssetRegistry::class)->keys())],
            'wallet' => 'required|string|max:255',
            'fee_rate' => 'nullable|numeric|min:0',
        ]);

        $assetKey = strtolower((string) $data['coin']);
        $asset = app(AssetRegistry::class)->get($assetKey);
        $networkKey = (string) $asset['network'];

        $wallet = SuperWallet::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
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
            'data' => $this->walletPayload($wallet),
        ], 201);
    }

    public function update(Request $request, Merchant $merchant, SuperWallet $wallet): JsonResponse
    {
        if ((int) $wallet->merchant_id !== $merchant->id) {
            abort(404);
        }

        $data = $request->validate([
            'wallet' => 'required|string|max:255',
            'fee_rate' => 'nullable|numeric|min:0',
        ]);

        $wallet->update([
            'wallet' => $data['wallet'],
            'fee_rate' => $data['fee_rate'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->walletPayload($wallet),
        ]);
    }

    public function destroy(Merchant $merchant, SuperWallet $wallet): JsonResponse
    {
        if ((int) $wallet->merchant_id !== $merchant->id) {
            abort(404);
        }

        $wallet->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function walletPayload(SuperWallet $wallet): array
    {
        return [
            'id' => $wallet->id,
            'coin' => strtoupper($wallet->coin),
            'asset_key' => $wallet->asset_key ?: strtolower((string) $wallet->coin),
            'network_key' => $wallet->network_key,
            'wallet' => $wallet->wallet,
            'fee_rate' => $wallet->fee_rate !== null ? (string) $wallet->fee_rate : null,
            'created_at' => optional($wallet->created_at)->toIso8601String(),
            'updated_at' => optional($wallet->updated_at)->toIso8601String(),
        ];
    }
}
