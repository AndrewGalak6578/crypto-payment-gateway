<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation rules for merchant invoice creation endpoint.
 */
class CreateInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'external_id' => 'nullable|string|max:120',
            'amount_usd' => 'required|numeric|min:0.01',
            'coin' => 'sometimes|string|in:dash,btc,ltc',
            'expires_minutes' => 'sometimes|integer|min:1|max:240',
            'metadata' => 'sometimes|array'
        ];
    }
}
