<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\AdminPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMerchantUserRequest;
use App\Http\Requests\Admin\UpdateMerchantUserRoleRequest;
use App\Http\Requests\Admin\UpdateMerchantUserStatusRequest;
use App\Models\MerchantUser;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantUserController extends Controller
{
    //
    public function index(Request $request): JsonResponse
    {
        $query = MerchantUser::query()
            ->with(['merchant', 'role'])
            ->latest('id');

        if ($merchantId = $request->query('merchant_id')) {
            $query->where('merchant_id', (int)$merchantId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($roleId = $request->query('role_id')) {
            $query->where('role_id', (int) $roleId);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function (Builder $q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }

                $q->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate((int) $request->query('per_page', 15));

        $roles = Role::query()
            ->latest('id')
            ->get(['id', 'slug', 'name']);

        return response()->json([
            'success' => true,
            'data' => $users->through(fn (MerchantUser $user) => [
                'id' => $user->id,
                'merchant_id' => $user->merchant_id,
                'merchant_name' => $user->merchant?->name,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'role_slug' => $user->role?->slug,
                'role_name' => $user->role?->name,
                'status' => $user->status,
                'last_login_at' => optional($user->last_login_at)->toIso8601String(),
                'created_at' => optional($user->created_at)->toIso8601String(),
            ]),
            'roles' => $roles->map(fn(Role $role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreMerchantUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = MerchantUser::query()->create([
            'merchant_id' => $data['merchant_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $data['role_id'],
            'status' => $data['status'],
        ]);

        $user->load(['merchant', 'role']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'merchant_id' => $user->merchant_id,
                'merchant_name' => $user->merchant?->name,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'role_slug' => $user->role?->slug,
                'role_name' => $user->role?->name,
                'status' => $user->status,
                'created_at' => optional($user->created_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function updateRole(UpdateMerchantUserRoleRequest $request, MerchantUser $merchantUser): JsonResponse
    {
        $merchantUser->update([
            'role_id' => $request->validated('role_id'),
        ]);

        $merchantUser->load('role');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $merchantUser->id,
                'role_id' => $merchantUser->role_id,
                'role_slug' => $merchantUser->role?->slug,
                'role_name' => $merchantUser->role?->name,
                'updated_at' => optional($merchantUser->updated_at)->toIso8601String(),
            ],
        ]);
    }

    public function updateStatus(UpdateMerchantUserStatusRequest $request, MerchantUser $merchantUser): JsonResponse
    {
        $merchantUser->update([
            'status' => $request->validated('status'),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $merchantUser->id,
                'status' => $merchantUser->status,
                'updated_at' => optional($merchantUser->updated_at)->toIso8601String(),
            ],
        ]);
    }
}
