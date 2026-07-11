<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use App\Models\SuperWallet;
use App\Services\MerchantActivityLogger;
use App\Support\Assets\AssetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request, MerchantActivityLogger $activity): JsonResponse
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
        $this->validateWalletAddress($data['wallet'], $networkKey);

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

        $activity->log($request, 'settlements', 'wallet.upserted', [
            'asset_key' => $wallet->asset_key,
            'network_key' => $wallet->network_key,
            'fee_rate' => $wallet->fee_rate !== null ? (string) $wallet->fee_rate : null,
        ], [
            'type' => 'security',
            'target_type' => SuperWallet::class,
            'target_id' => $wallet->id,
            'target_label' => strtoupper((string) $wallet->coin),
        ]);

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

    public function update(Request $request, int $id, MerchantActivityLogger $activity): JsonResponse
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
        $this->validateWalletAddress($data['wallet'], $wallet->network_key ?: $wallet->resolvedNetworkKey());

        $previousWallet = $wallet->wallet;
        $previousFeeRate = $wallet->fee_rate !== null ? (string) $wallet->fee_rate : null;

        $wallet->update([
            'wallet' => $data['wallet'],
            'fee_rate' => $data['fee_rate'] ?? null,
        ]);

        $activity->log($request, 'settlements', 'wallet.updated', [
            'asset_key' => $wallet->asset_key ?: strtolower((string) $wallet->coin),
            'network_key' => $wallet->network_key,
            'wallet_changed' => $previousWallet !== $wallet->wallet,
            'previous_fee_rate' => $previousFeeRate,
            'next_fee_rate' => $wallet->fee_rate !== null ? (string) $wallet->fee_rate : null,
        ], [
            'type' => 'security',
            'target_type' => SuperWallet::class,
            'target_id' => $wallet->id,
            'target_label' => strtoupper((string) $wallet->coin),
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

    public function destroy(Request $request, int $id, MerchantActivityLogger $activity): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        $wallet = SuperWallet::query()
            ->where('merchant_id', $merchantUser->merchant_id)
            ->findOrFail($id);

        $walletId = $wallet->id;
        $walletCoin = strtoupper((string) $wallet->coin);
        $walletAsset = $wallet->asset_key ?: strtolower((string) $wallet->coin);
        $walletNetwork = $wallet->network_key;

        $wallet->delete();

        $activity->log($request, 'settlements', 'wallet.deleted', [
            'asset_key' => $walletAsset,
            'network_key' => $walletNetwork,
        ], [
            'type' => 'security',
            'target_type' => SuperWallet::class,
            'target_id' => $walletId,
            'target_label' => $walletCoin,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * @throws ValidationException
     */
    private function validateWalletAddress(string $wallet, string $networkKey): void
    {
        if (preg_match('/\s/', $wallet) === 1 || preg_match('/[[:cntrl:]]/', $wallet) === 1) {
            throw ValidationException::withMessages([
                'wallet' => 'Wallet address must not contain whitespace or control characters.',
            ]);
        }

        if ($networkKey === 'evm_local' && preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet) !== 1) {
            throw ValidationException::withMessages([
                'wallet' => 'EVM wallet address must be a valid 0x-prefixed address.',
            ]);
        }
    }
}
