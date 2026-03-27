<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\AdminPortal;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\DashboardResource;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => DashboardResource::make($request),
        ]);
    }
}
