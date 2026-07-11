<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\MerchantPortal\StoreMerchantUserRequest;
use App\Http\Requests\MerchantPortal\UpdateMerchantUserRoleRequest;
use App\Http\Requests\MerchantPortal\UpdateMerchantUserStatusRequest;
use App\Models\MerchantActivityLog;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Services\MerchantActivityLogger;
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
            ->where('merchant_id', $merchantUser->merchant_id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($roleId = $request->query('role_id')) {
            $query->where('role_id', (int) $roleId);
        }

        if ($createdFrom = $request->query('created_from')) {
            $query->whereDate('created_at', '>=', $createdFrom);
        }

        if ($createdTo = $request->query('created_to')) {
            $query->whereDate('created_at', '<=', $createdTo);
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

        $sort = (string) $request->query('sort', 'created_at');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumn = match ($sort) {
            'name' => 'name',
            'email' => 'email',
            'status' => 'status',
            'last_login_at' => 'last_login_at',
            default => 'created_at',
        };

        $query->orderBy($sortColumn, $direction)
            ->orderBy('id', $direction);

        $users = $query->paginate((int) $request->query('per_page', 15));
        $roles = $this->merchantRoles();

        return response()->json([
            'success' => true,
            'data' => $users->through(fn (MerchantUser $user) => $this->userPayload($user)),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'description' => $role->description,
                'capability_count' => $role->capabilities->count(),
                'capabilities' => $role->capabilities->map(fn ($capability) => [
                    'id' => $capability->id,
                    'code' => $capability->code,
                    'name' => $capability->name,
                    'description' => $capability->description,
                ])->values(),
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function show(Request $request, MerchantUser $merchantUser): JsonResponse
    {
        $actor = $this->currentMerchantUser($request);
        $merchantUser = $this->scopedMerchantUser($merchantUser, $actor);
        $merchantUser->load(['role.capabilities']);

        $logs = MerchantActivityLog::query()
            ->with('actor:id,name,email')
            ->where('merchant_id', $actor->merchant_id)
            ->where(function (Builder $query) use ($merchantUser): void {
                $query
                    ->where('actor_merchant_user_id', $merchantUser->id)
                    ->orWhere('subject_merchant_user_id', $merchantUser->id);
            })
            ->latest('id')
            ->paginate((int) $request->query('per_page', 25));

        $statsQuery = MerchantActivityLog::query()
            ->where('merchant_id', $actor->merchant_id)
            ->where(function (Builder $query) use ($merchantUser): void {
                $query
                    ->where('actor_merchant_user_id', $merchantUser->id)
                    ->orWhere('subject_merchant_user_id', $merchantUser->id);
            });
        $lastEvent = (clone $statsQuery)->latest('id')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->userPayload($merchantUser),
                'role' => $merchantUser->role ? [
                    'id' => $merchantUser->role->id,
                    'slug' => $merchantUser->role->slug,
                    'name' => $merchantUser->role->name,
                    'description' => $merchantUser->role->description,
                    'capabilities' => $merchantUser->role->capabilities->map(fn ($capability) => [
                        'id' => $capability->id,
                        'code' => $capability->code,
                        'name' => $capability->name,
                        'description' => $capability->description,
                    ])->values(),
                ] : null,
                'stats' => [
                    'total_events' => (clone $statsQuery)->count(),
                    'write_events' => (clone $statsQuery)->where('type', 'write')->count(),
                    'security_events' => (clone $statsQuery)->where('type', 'security')->count(),
                    'last_event_at' => optional($lastEvent?->created_at)->toIso8601String(),
                ],
                'activity' => $logs->through(fn (MerchantActivityLog $log) => $this->activityPayload($log)),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ],
        ]);
    }

    public function store(StoreMerchantUserRequest $request, MerchantActivityLogger $activity): JsonResponse
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

        $activity->log($request, 'team', 'merchant_user.created', [
            'created_email' => $user->email,
            'created_role' => $user->role?->slug,
            'created_status' => $user->status,
        ], [
            'type' => 'write',
            'subject_merchant_user_id' => $user->id,
            'target_type' => MerchantUser::class,
            'target_id' => $user->id,
            'target_label' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->userPayload($user),
        ], 201);
    }

    public function updateRole(UpdateMerchantUserRoleRequest $request, MerchantUser $merchantUser, MerchantActivityLogger $activity): JsonResponse
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

        $previousRole = $merchantUser->role?->slug;
        $merchantUser->update(['role_id' => $role->id]);
        $merchantUser->load('role');

        $activity->log($request, 'team', 'merchant_user.role_updated', [
            'previous_role' => $previousRole,
            'next_role' => $merchantUser->role?->slug,
            'email' => $merchantUser->email,
        ], [
            'type' => 'security',
            'subject_merchant_user_id' => $merchantUser->id,
            'target_type' => MerchantUser::class,
            'target_id' => $merchantUser->id,
            'target_label' => $merchantUser->email,
        ]);

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

    public function updateStatus(UpdateMerchantUserStatusRequest $request, MerchantUser $merchantUser, MerchantActivityLogger $activity): JsonResponse
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

        $previousStatus = $merchantUser->status;
        $merchantUser->update(['status' => $nextStatus]);

        $activity->log($request, 'team', 'merchant_user.status_updated', [
            'previous_status' => $previousStatus,
            'next_status' => $merchantUser->status,
            'email' => $merchantUser->email,
        ], [
            'type' => 'security',
            'subject_merchant_user_id' => $merchantUser->id,
            'target_type' => MerchantUser::class,
            'target_id' => $merchantUser->id,
            'target_label' => $merchantUser->email,
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

    public function destroy(Request $request, MerchantUser $merchantUser, MerchantActivityLogger $activity): JsonResponse
    {
        $actor = $this->currentMerchantUser($request);
        $merchantUser = $this->scopedMerchantUser($merchantUser, $actor);

        if (
            $merchantUser->status === 'active'
            && $merchantUser->role?->slug === 'merchant.owner'
            && $this->activeOwnerCount($actor->merchant_id) <= 1
        ) {
            return response()->json([
                'success' => false,
                'message' => 'At least one active owner must remain.',
            ], 422);
        }

        $deletedUserId = $merchantUser->id;
        $deletedEmail = $merchantUser->email;
        $deletedRole = $merchantUser->role?->slug;

        $activity->log($request, 'team', 'merchant_user.deleted', [
            'deleted_email' => $deletedEmail,
            'deleted_role' => $deletedRole,
        ], [
            'type' => 'security',
            'subject_merchant_user_id' => $deletedUserId,
            'target_type' => MerchantUser::class,
            'target_id' => $deletedUserId,
            'target_label' => $deletedEmail,
        ]);

        $merchantUser->tokens()->delete();
        $merchantUser->delete();

        return response()->json([
            'success' => true,
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
            ->with(['capabilities' => fn ($query) => $query->orderBy('code')])
            ->where('slug', 'like', 'merchant.%')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'description']);
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

    private function activityPayload(MerchantActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'section' => $log->section,
            'type' => $log->type,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'target_label' => $log->target_label,
            'metadata' => $log->metadata ?? [],
            'ip_address' => $log->ip_address,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'email' => $log->actor->email,
            ] : null,
            'created_at' => optional($log->created_at)->toIso8601String(),
        ];
    }
}
