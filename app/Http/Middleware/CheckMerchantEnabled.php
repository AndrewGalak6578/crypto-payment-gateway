<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckMerchantEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $merchantUser = Auth::guard('merchant')->user();

        if (!$merchantUser) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($merchantUser->status !== 'active') {
            return response()->json(['message' => 'Merchant user is inactive.'], 403);
        }

        return $next($request);
    }
}
