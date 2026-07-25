<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Exceptions\StaleSettlementPreferenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MerchantPortal\UpdateSettlementPolicyRequest;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Services\MerchantPortalAccess;
use App\Services\MerchantSettlementPolicyPresenter;
use App\Services\MerchantSettlementPolicyUpdater;
use App\Support\Assets\AssetRegistry;
use App\Support\Http\MerchantRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettlementPolicyController extends Controller
{
    public function index(
        Request $request,
        MerchantPortalAccess $access,
        MerchantSettlementPolicyPresenter $presenter,
        AssetRegistry $assets,
        MerchantRequestId $requestIds,
    ): JsonResponse {
        $actor = $this->actor($request);
        $merchant = Merchant::query()->findOrFail($actor->merchant_id);
        $canWrite = $access->can($actor, 'settlements.write');

        $policies = collect($assets->all())
            ->keys()
            ->map(fn (string $assetKey): array => $presenter->present($merchant, $assetKey, $canWrite))
            ->values();

        return response()->json([
            'success' => true,
            'request_id' => $requestIds->for($request),
            'data' => [
                'permissions' => [
                    'can_read' => true,
                    'can_write' => $canWrite,
                ],
                'policies' => $policies,
            ],
        ]);
    }

    public function update(
        UpdateSettlementPolicyRequest $request,
        string $assetKey,
        MerchantPortalAccess $access,
        MerchantSettlementPolicyUpdater $updater,
        MerchantSettlementPolicyPresenter $presenter,
        AssetRegistry $assets,
        MerchantRequestId $requestIds,
    ): JsonResponse {
        $assetKey = strtolower($assetKey);
        abort_unless($assets->exists($assetKey, false), 404);

        $actor = $this->actor($request);
        $validated = $request->validated();

        try {
            $updater->update(
                request: $request,
                actor: $actor,
                assetKey: $assetKey,
                expectedRevision: (int) $validated['revision'],
                requested: $validated['requested'],
            );
        } catch (StaleSettlementPreferenceException $exception) {
            $merchant = Merchant::query()->findOrFail($actor->merchant_id);

            return response()->json([
                'success' => false,
                'request_id' => $requestIds->for($request),
                'code' => 'settlement_policy_revision_conflict',
                'message' => $exception->getMessage(),
                'data' => [
                    'policy' => $presenter->present(
                        $merchant,
                        $assetKey,
                        $access->can($actor, 'settlements.write'),
                    ),
                ],
            ], 409);
        }

        $merchant = Merchant::query()->findOrFail($actor->merchant_id);

        return response()->json([
            'success' => true,
            'request_id' => $requestIds->for($request),
            'data' => [
                'policy' => $presenter->present($merchant, $assetKey, true),
            ],
        ]);
    }

    private function actor(Request $request): MerchantUser
    {
        /** @var MerchantUser $actor */
        $actor = $request->attributes->get('merchant_user');

        return $actor;
    }
}
