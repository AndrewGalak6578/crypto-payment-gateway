<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use App\Services\MerchantPortal\DashboardMetricsService;
use App\Services\MerchantPortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        MerchantPortalAccess $access,
        DashboardMetricsService $dashboardMetrics,
    ): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');

        return response()->json([
            'success' => true,
            'data' => $dashboardMetrics->forMerchant((int) $merchantUser->merchant_id, [
                'invoices.write' => $access->can($merchantUser, 'invoices.write'),
                'webhooks.read' => $access->can($merchantUser, 'webhooks.read'),
                'webhooks.write' => $access->can($merchantUser, 'webhooks.write'),
                'wallets.write' => $access->can($merchantUser, 'wallets.write'),
            ]),
        ]);
    }
}
