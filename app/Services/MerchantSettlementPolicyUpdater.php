<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SettlementPolicyConfiguration;
use App\Exceptions\StaleSettlementPreferenceException;
use App\Models\AssetPolicy;
use App\Models\Merchant;
use App\Models\MerchantSettlementPreference;
use App\Models\MerchantUser;
use App\Services\Settlement\SettlementDecimal;
use App\Support\Assets\AssetRegistry;
use App\Support\Http\MerchantRequestId;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MerchantSettlementPolicyUpdater
{
    public function __construct(
        private SettlementPolicyResolver $policies,
        private SettlementDecimal $decimal,
        private AssetRegistry $assets,
        private MerchantActivityLogger $activity,
        private MerchantRequestId $requestIds,
    ) {}

    /** @param array{mode: ?string, minimum_invoice_payout: ?string} $requested */
    public function update(
        Request $request,
        MerchantUser $actor,
        string $assetKey,
        int $expectedRevision,
        array $requested,
    ): MerchantSettlementPreference {
        $assetKey = strtolower($assetKey);
        $asset = $this->assets->get($assetKey);
        $networkKey = (string) $asset['network'];
        $requestId = $this->requestIds->for($request);

        return DB::transaction(function () use (
            $request,
            $actor,
            $assetKey,
            $networkKey,
            $expectedRevision,
            $requested,
            $requestId,
        ): MerchantSettlementPreference {
            /** @var Merchant $merchant */
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($actor->merchant_id);
            $before = $this->policies->resolveForMerchantAsset($merchant, $assetKey, $networkKey, true);

            $preference = MerchantSettlementPreference::query()
                ->where('merchant_id', $merchant->id)
                ->where('asset_key', $assetKey)
                ->where('network_key', $networkKey)
                ->lockForUpdate()
                ->first();
            $currentRevision = (int) ($preference?->revision ?? 0);

            if ($currentRevision !== $expectedRevision) {
                throw new StaleSettlementPreferenceException($currentRevision);
            }

            if ($currentRevision >= MerchantSettlementPreference::MAX_EXPECTED_REVISION) {
                throw ValidationException::withMessages([
                    'revision' => 'Settlement preference revision limit has been reached.',
                ]);
            }

            $mode = $requested['mode'];
            $minimum = $requested['minimum_invoice_payout'];
            $normalizedMinimum = $minimum === null ? null : $this->decimal->format($minimum, $assetKey);

            $this->assertAllowedByAdminPolicy($before, $mode, $normalizedMinimum);

            if ($preference === null) {
                $preference = new MerchantSettlementPreference([
                    'merchant_id' => $merchant->id,
                    'asset_key' => $assetKey,
                    'network_key' => $networkKey,
                    'revision' => 1,
                ]);
            } else {
                $preference->revision = $currentRevision + 1;
            }

            $preference->requested_mode = $mode;
            $preference->requested_minimum_invoice_payout = $normalizedMinimum;
            $preference->save();

            $after = $this->policies->resolveForMerchantAsset($merchant, $assetKey, $networkKey, true);
            $actor->loadMissing('role');

            $this->activity->log($request, 'settlements', 'settlement_policy.updated', [
                'request_id' => $requestId,
                'asset_key' => $assetKey,
                'network_key' => $networkKey,
                'actor' => [
                    'merchant_user_id' => $actor->id,
                    'role_slug' => $actor->role?->slug,
                ],
                'previous_requested' => $before->requestedValues(),
                'new_requested' => $after->requestedValues(),
                'previous_effective' => $before->effectiveValues(),
                'new_effective' => $after->effectiveValues(),
                'revision' => [
                    'previous' => $currentRevision,
                    'new' => $preference->revision,
                ],
            ], [
                'type' => 'write',
                'target_type' => MerchantSettlementPreference::class,
                'target_id' => $preference->id,
                'target_label' => strtoupper($assetKey),
            ]);

            return $preference;
        });
    }

    private function assertAllowedByAdminPolicy(
        SettlementPolicyConfiguration $inherited,
        ?string $mode,
        ?string $minimum,
    ): void {
        if ($mode === null || $mode === AssetPolicy::MODE_DISABLED) {
            return;
        }

        if (! $inherited->assetEnabled || ! $inherited->forwardingEnabled) {
            throw ValidationException::withMessages([
                'requested.mode' => 'Platform policy does not allow automatic forwarding for this asset.',
            ]);
        }

        if (! $this->policies->canMerchantRequestMode($inherited->inheritedMode, $mode)) {
            throw ValidationException::withMessages([
                'requested.mode' => 'Requested mode is less restrictive than platform policy.',
            ]);
        }

        if ($mode === AssetPolicy::MODE_THRESHOLD && $minimum !== null && $inherited->inheritedMinimumInvoicePayout !== null
            && BigDecimal::of($minimum)->compareTo(BigDecimal::of($inherited->inheritedMinimumInvoicePayout)) < 0) {
            throw ValidationException::withMessages([
                'requested.minimum_invoice_payout' => 'Minimum invoice payout cannot be lower than the platform minimum.',
            ]);
        }
    }
}
