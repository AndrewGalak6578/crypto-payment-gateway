<?php

namespace App\Http\Middleware;

use App\Models\MerchantApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMerchantApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $auth = (string)$request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['success' => false, 'error' => 'Missing Bearer Token'], 401);
        }

        $token = trim(substr($auth, 7));
        if ($token === '') {
            return response()->json(['success' => false, 'error' => 'Empty token'], 401);
        }

        $hash = hash('sha256', $token);

        $key = MerchantApiKey::query()
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->with('merchant')
            ->firstOrFail();

        if (!$key || !$key->merchant || $key->merchant->status !== 'active') {
            return response()->json(['success' => false, 'error' => 'Invalid API key'], 401);
        }

        $key->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('merchant', $key->merchant);
        $request->attributes->set('merchant_api_key', $key);

        return $next($request);
    }
}
