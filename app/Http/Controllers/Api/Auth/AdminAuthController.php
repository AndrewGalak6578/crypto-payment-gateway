<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Auth\MerchantLoginRequest;
use App\Models\AdminUser;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $creds = $request->validated();
        /** @var AdminUser $user */
        $user = AdminUser::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.'],
                422);
        }

        if (!$user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant user is inactive.'],
                403);
        }

        if (!Auth::guard('admin')->attempt($creds, remember: false)) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant is inactive.'],
                403);
        }

        Auth::guard('admin')->login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now('UTC'),
        ])->save();

        return response()->json([
            'success' => true,
            'data' => $this->payload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }


        return response()->json([
            'success' => true,
            'data' => $this->payload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
        ]);
    }

    private function payload(AdminUser $user): array
    {
        return [
            'admin' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'last_login_at' => optional($user->last_login_at)->toIso8601String(),
            ],
        ];
    }
}
