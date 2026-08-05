<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $identifier = $guard->id();

        if ($identifier === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $admin = $guard->getProvider()->retrieveById($identifier);

        if (! $admin || ! $admin->isActive()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'success' => false,
                'message' => 'Admin user is inactive.',
            ], 403);
        }

        $guard->setUser($admin);
        $request->attributes->set('admin_user', $admin);

        return $next($request);
    }
}
