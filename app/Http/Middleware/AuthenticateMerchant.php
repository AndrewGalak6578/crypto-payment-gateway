<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMerchant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('merchant');
        $identifier = $guard->id();

        if ($identifier === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $merchantUser = $guard->getProvider()->retrieveById($identifier);
        $merchant = $merchantUser?->merchant()->first();

        if (
            ! $merchantUser
            || $merchantUser->status !== 'active'
            || ! $merchant
            || $merchant->status !== 'active'
        ) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'success' => false,
                'message' => 'Merchant session is inactive.',
            ], 403);
        }

        $merchantUser->setRelation('merchant', $merchant);
        $guard->setUser($merchantUser);
        $request->attributes->set('merchant_user', $merchantUser);
        $request->attributes->set('merchant', $merchant);

        return $next($request);
    }
}
