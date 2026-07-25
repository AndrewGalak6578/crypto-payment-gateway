<?php

declare(strict_types=1);

namespace App\Http\Requests\MerchantPortal;

use App\Models\AssetPolicy;
use App\Models\MerchantSettlementPreference;
use App\Support\Assets\AssetRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateSettlementPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'revision' => [
                'required',
                'integer',
                'min:0',
                'max:'.MerchantSettlementPreference::MAX_EXPECTED_REVISION,
            ],
            'requested' => ['required', 'array'],
            'requested.mode' => ['present', 'nullable', 'string'],
            'requested.minimum_invoice_payout' => ['present', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('revision') && ! is_int($this->input('revision'))) {
                $validator->errors()->add('revision', 'Revision must be a JSON integer.');
            }

            $unknownTopLevel = array_diff(array_keys($this->all()), ['revision', 'requested']);
            foreach ($unknownTopLevel as $field) {
                $validator->errors()->add($field, 'Unknown field.');
            }

            $requested = $this->input('requested');
            if (! is_array($requested)) {
                return;
            }

            $unknownRequested = array_diff(array_keys($requested), ['mode', 'minimum_invoice_payout']);
            foreach ($unknownRequested as $field) {
                $validator->errors()->add("requested.{$field}", 'Unknown field.');
            }

            $mode = $requested['mode'] ?? null;
            $minimum = $requested['minimum_invoice_payout'] ?? null;

            if ($mode === AssetPolicy::MODE_MANUAL) {
                $validator->errors()->add('requested.mode', 'Manual settlement is unavailable until operator release is implemented.');
            } elseif ($mode === AssetPolicy::MODE_INTERNAL_BALANCE_ONLY) {
                $validator->errors()->add('requested.mode', 'Internal balance settlement is controlled by the platform administrator.');
            } elseif ($mode !== null && ! in_array($mode, [
                AssetPolicy::MODE_IMMEDIATE,
                AssetPolicy::MODE_THRESHOLD,
                AssetPolicy::MODE_DISABLED,
            ], true)) {
                $validator->errors()->add('requested.mode', 'Unsupported settlement mode.');
            }

            if ($mode !== AssetPolicy::MODE_THRESHOLD) {
                if ($minimum !== null) {
                    $validator->errors()->add(
                        'requested.minimum_invoice_payout',
                        'Minimum invoice payout must be null unless threshold mode is selected.',
                    );
                }

                return;
            }

            if (! is_string($minimum) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $minimum) !== 1) {
                $validator->errors()->add('requested.minimum_invoice_payout', 'Enter a positive decimal amount without exponent notation.');

                return;
            }

            $assetKey = strtolower((string) $this->route('assetKey'));
            $assets = app(AssetRegistry::class);
            if (! $assets->exists($assetKey, false)) {
                return;
            }

            $integerDigits = strlen(explode('.', $minimum, 2)[0]);
            if ($integerDigits > 18) {
                $validator->errors()->add(
                    'requested.minimum_invoice_payout',
                    'Amount supports at most 18 digits before the decimal point.',
                );
            }

            $fraction = str_contains($minimum, '.') ? strlen(explode('.', $minimum, 2)[1]) : 0;
            if ($fraction > $assets->settlementScale($assetKey)) {
                $validator->errors()->add(
                    'requested.minimum_invoice_payout',
                    "Amount supports at most {$assets->settlementScale($assetKey)} decimal places.",
                );
            }

            if (BigDecimal::of($minimum)->compareTo(BigDecimal::zero()) <= 0) {
                $validator->errors()->add('requested.minimum_invoice_payout', 'Minimum invoice payout must be greater than zero.');
            }
        });
    }
}
