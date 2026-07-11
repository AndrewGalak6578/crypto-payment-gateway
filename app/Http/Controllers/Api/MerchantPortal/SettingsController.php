<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Services\MerchantActivityLogger;
use App\Support\Assets\AssetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        return response()->json([
            'success' => true,
            'data' => $this->payload($merchant),
        ]);
    }

    public function update(Request $request, AssetRegistry $assets, MerchantActivityLogger $activity): JsonResponse
    {
        $merchant = $this->currentMerchant($request);

        $data = $request->validate([
            'checkout_display_name' => ['nullable', 'string', 'max:120'],
            'checkout_support_email' => ['nullable', 'email', 'max:255'],
            'checkout_brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'checkout_expires_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'checkout_payer_can_choose_asset' => ['required', 'boolean'],
            'checkout_default_asset' => ['nullable', 'string', Rule::in($assets->keys())],
            'checkout_allowed_assets' => ['nullable', 'array'],
            'checkout_allowed_assets.*' => ['string', Rule::in($assets->keys())],
            'checkout_success_url' => ['nullable', 'url', 'max:2048'],
            'checkout_cancel_url' => ['nullable', 'url', 'max:2048'],
            'checkout_auto_redirect' => ['required', 'boolean'],
            'checkout_redirect_delay_seconds' => ['required', 'integer', 'min:0', 'max:30'],
            'checkout_show_invoice_id' => ['required', 'boolean'],
            'checkout_show_support_email' => ['required', 'boolean'],
            'checkout_partial_payment_policy' => ['required', Rule::in(['allow_top_up', 'support_required', 'expire_on_partial'])],
            'checkout_confirmation_display' => ['required', Rule::in(['simple', 'show_confirmations'])],
            'checkout_min_amount_usd' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'checkout_max_amount_usd' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
        ]);

        if (($data['checkout_payer_can_choose_asset'] ?? true) === false && empty($data['checkout_default_asset'])) {
            return response()->json([
                'success' => false,
                'message' => 'Default asset is required when payer asset selection is disabled.',
                'errors' => [
                    'checkout_default_asset' => ['Default asset is required when payer asset selection is disabled.'],
                ],
            ], 422);
        }

        if (
            ! empty($data['checkout_default_asset'])
            && ! empty($data['checkout_allowed_assets'])
            && ! in_array($data['checkout_default_asset'], $data['checkout_allowed_assets'], true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Default asset must be included in allowed assets.',
                'errors' => [
                    'checkout_default_asset' => ['Default asset must be included in allowed assets.'],
                ],
            ], 422);
        }

        if (
            isset($data['checkout_min_amount_usd'], $data['checkout_max_amount_usd'])
            && (float) $data['checkout_min_amount_usd'] > (float) $data['checkout_max_amount_usd']
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum amount cannot be greater than maximum amount.',
                'errors' => [
                    'checkout_min_amount_usd' => ['Minimum amount cannot be greater than maximum amount.'],
                ],
            ], 422);
        }

        $data['checkout_allowed_assets'] = array_values(array_unique($data['checkout_allowed_assets'] ?? [])) ?: null;

        $before = $merchant->only(array_keys($data));
        $merchant->update($data);
        $fresh = $merchant->fresh();

        $activity->log($request, 'settings', 'checkout_settings.updated', [
            'changed_fields' => collect($data)
                ->filter(fn ($value, string $key) => ($before[$key] ?? null) != $value)
                ->keys()
                ->values()
                ->all(),
        ], [
            'type' => 'write',
            'target_type' => Merchant::class,
            'target_id' => $merchant->id,
            'target_label' => $merchant->name,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->payload($fresh),
        ]);
    }

    private function currentMerchant(Request $request): Merchant
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        return Merchant::query()->findOrFail($merchantUser->merchant_id);
    }

    private function payload(Merchant $merchant): array
    {
        return [
            'profile' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'status' => $merchant->status,
            ],
            'billing' => [
                'fee_percent' => $merchant->fee_percent,
            ],
            'checkout' => [
                'display_name' => $merchant->checkout_display_name,
                'support_email' => $merchant->checkout_support_email,
                'brand_color' => $merchant->checkout_brand_color,
                'expires_minutes' => $merchant->checkout_expires_minutes,
                'payer_can_choose_asset' => $merchant->checkout_payer_can_choose_asset,
                'default_asset' => $merchant->checkout_default_asset,
                'allowed_assets' => $merchant->checkout_allowed_assets ?? [],
                'success_url' => $merchant->checkout_success_url,
                'cancel_url' => $merchant->checkout_cancel_url,
                'auto_redirect' => $merchant->checkout_auto_redirect,
                'redirect_delay_seconds' => $merchant->checkout_redirect_delay_seconds,
                'show_invoice_id' => $merchant->checkout_show_invoice_id,
                'show_support_email' => $merchant->checkout_show_support_email,
                'partial_payment_policy' => $merchant->checkout_partial_payment_policy,
                'confirmation_display' => $merchant->checkout_confirmation_display,
                'min_amount_usd' => $merchant->checkout_min_amount_usd,
                'max_amount_usd' => $merchant->checkout_max_amount_usd,
            ],
        ];
    }
}
