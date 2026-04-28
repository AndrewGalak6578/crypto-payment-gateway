<?php
declare(strict_types=1);

namespace App\Http\Requests\MerchantPortal;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMerchantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:merchant_users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')],
            'status' => ['required', 'string', Rule::in(['active', 'disabled'])],
        ];
    }
}
