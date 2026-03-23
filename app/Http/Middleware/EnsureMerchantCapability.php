<?php

namespace App\Http\Middleware;

use App\Models\MerchantUser;
use App\Services\MerchantPortalAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantCapability
{

    public function __construct(
        private readonly MerchantPortalAccess $access
    ) {
    }
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        if (!$merchantUser || !$this->access->can($merchantUser, $capability)) {
            return \response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
