<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\MerchantPortal\StoreMerchantUserRequest;
use App\Http\Requests\MerchantPortal\UpdateMerchantUserRoleRequest;
use App\Http\Requests\MerchantPortal\UpdateMerchantUserStatusRequest;
use App\Models\MerchantUser;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $merchantUser = $this->currentMerchantUser($request);

        $query = MerchantUser::query()
            ->with('role')
            ->where('merchant_id', $merchantUser->merchant_id)
            ->latest('id');

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
        $roles = $this->merchantRoles();

        return response()->json([
            'success' => true,
            'data' => $users->through(fn (MerchantUser $user) => $this->userPayload($user)),
            'roles' => $roles->map(fn (Role $role) => [
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
        $merchantUser = $this->currentMerchantUser($request);
        $data = $request->validated();
        $role = $this->merchantRoleById((int) $data['role_id']);

        $user = MerchantUser::query()->create([
            'merchant_id' => $merchantUser->merchant_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $role->id,
            'status' => $data['status'],
        ]);

        $user->load('role');

        return response()->json([
            'success' => true,
            'data' => $this->userPayload($user),
        ], 201);
    }

    public function updateRole(UpdateMerchantUserRoleRequest $request, MerchantUser $merchantUser): JsonResponse
    {
        $actor = $this->currentMerchantUser($request);
        $merchantUser = $this->scopedMerchantUser($merchantUser, $actor);
        $role = $this->merchantRoleById((int) $request->validated('role_id'));

        if (
            $merchantUser->status === 'active'
            && $merchantUser->role?->slug === 'merchant.owner'
            && $role->slug !== 'merchant.owner'
            && $this->activeOwnerCount($actor->merchant_id) <= 1
        ) {
            return response()->json([
                'success' => false,
                'message' => 'At least one active owner must remain.',
            ], 422);
        }

        $merchantUser->update(['role_id' => $role->id]);
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
        $actor = $this->currentMerchantUser($request);
        $merchantUser = $this->scopedMerchantUser($merchantUser, $actor);
        $nextStatus = $request->validated('status');

        if (
            $merchantUser->status === 'active'
            && $merchantUser->role?->slug === 'merchant.owner'
            && $nextStatus !== 'active'
            && $this->activeOwnerCount($actor->merchant_id) <= 1
        ) {
            return response()->json([
                'success' => false,
                'message' => 'At least one active owner must remain.',
            ], 422);
        }

        $merchantUser->update(['status' => $nextStatus]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $merchantUser->id,
                'status' => $merchantUser->status,
                'updated_at' => optional($merchantUser->updated_at)->toIso8601String(),
            ],
        ]);
    }

    private function currentMerchantUser(Request $request): MerchantUser
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        return $merchantUser;
    }

    private function scopedMerchantUser(MerchantUser $merchantUser, MerchantUser $actor): MerchantUser
    {
        $merchantUser->loadMissing('role');

        abort_unless($merchantUser->merchant_id === $actor->merchant_id, 404);

        return $merchantUser;
    }

    private function merchantRoles()
    {
        return Role::query()
            ->where('slug', 'like', 'merchant.%')
            ->orderBy('id')
            ->get(['id', 'slug', 'name']);
    }

    private function merchantRoleById(int $roleId): Role
    {
        return Role::query()
            ->where('id', $roleId)
            ->where('slug', 'like', 'merchant.%')
            ->firstOrFail();
    }

    private function activeOwnerCount(int $merchantId): int
    {
        return MerchantUser::query()
            ->where('merchant_id', $merchantId)
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'merchant.owner'))
            ->count();
    }

    private function userPayload(MerchantUser $user): array
    {
        return [
            'id' => $user->id,
            'merchant_id' => $user->merchant_id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role_slug' => $user->role?->slug,
            'role_name' => $user->role?->name,
            'status' => $user->status,
            'last_login_at' => optional($user->last_login_at)->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
        ];
    }
}
