<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MerchantLoginRequest;
use App\Http\Requests\Auth\MerchantRegisterRequest;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Role;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class MerchantAuthController extends Controller
{
    public function register(MerchantRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): MerchantUser {
            $ownerRole = Role::query()
                ->where('slug', 'merchant.owner')
                ->first();

            if (!$ownerRole) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Merchant owner role is not configured.',
                ], 500));
            }

            $merchant = Merchant::query()->create([
                'name' => $data['merchant_name'],
                'status' => 'active',
                'fee_percent' => 2.00,
            ]);

            return MerchantUser::query()->create([
                'merchant_id' => $merchant->id,
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role_id' => $ownerRole->id,
                'status' => 'active',
            ]);
        });

        Auth::guard('merchant')->login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now('UTC'),
        ])->save();

        $user->load('merchant');
        $user->load('role');
        $user->load('role.capabilities');

        return response()->json([
            'success' => true,
            'data' => $this->payload($user),
        ], 201);
    }

    public function login(MerchantLoginRequest $request): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = MerchantUser::query()
            ->with('merchant')
            ->where('email', $request->string('email')->toString())
            ->first();

        if (!$user || !Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.'],
                422);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Merchant user is inactive.'],
                403);
        }

        if (!$user->merchant || $user->merchant->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Merchant is inactive.'],
                403);
        }

        Auth::guard('merchant')->login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now('UTC'),
        ])->save();

        return response()->json([
            'success' => true,
            'data' => ''
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = Auth::guard('merchant')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $user->load('merchant');
        $user->load('role');
        $user->load('role.capabilities');

        return response()->json([
            'success' => true,
            'data' => $this->payload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('merchant')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
        ]);
    }

    private function payload(MerchantUser $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'merchant_id' => $user->merchant_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'last_login_at' => optional($user->last_login_at)->toIso8601String(),
                'role' => [
                    'slug' => $user->role->slug,
                    'name' => $user->role->name,
                ],
                'capabilities' => $user->role->capabilities->pluck('code')->values()->all(),
            ],
            'merchant' => [
                'id' => $user->merchant->id,
                'name' => $user->merchant->name,
                'status' => $user->merchant->status,
                'fee_percent' => (string)$user->merchant->fee_percent,
                'webhook_url' => $user->merchant->webhook_url,
                'has_webhook_secret' => !empty($user->merchant->webhook_secret),
            ]
        ];
    }
}
