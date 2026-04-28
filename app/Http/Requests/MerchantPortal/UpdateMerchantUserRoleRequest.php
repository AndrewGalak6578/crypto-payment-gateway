<?php
declare(strict_types=1);

namespace App\Http\Requests\MerchantPortal;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')],
        ];
    }
}
