<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Services\AdminPortalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureAdminCapability
{
    public function __construct(private AdminPortalAccess $access) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $admin = $request->attributes->get('admin_user');

        if (! $admin instanceof AdminUser || ! $this->access->can($admin, $capability)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
