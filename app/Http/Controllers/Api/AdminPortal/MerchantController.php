<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\AdminPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMerchantStatusRequest;
use App\Models\Invoice;
use App\Models\Merchant;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    //
    public function index(Request $request): JsonResponse
    {
        $query = Merchant::query()->latest('id');

        if ($search = trim((string)$request->input('search'))) {
            $query->where(function (Builder $q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int)$search);
                }

                $q->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $merchants = $query->paginate((int)$request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $merchants->through(fn(Merchant $merchant) => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'status' => $merchant->status,
                'fee_percent' => $merchant->fee_percent !== null ? (string)$merchant->fee_percent : null,
                'webhook_url' => $merchant->webhook_url,
                'created_at' => $merchant->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $merchants->currentPage(),
                'last_page' => $merchants->lastPage(),
                'per_page' => $merchants->perPage(),
                'total' => $merchants->total(),
            ]
        ]);
    }

    public function show(Merchant $merchant): JsonResponse
    {
        $merchant->load('users.role');

        $recent_invoices = Invoice::query()
            ->where('merchant_id', $merchant->id)
            ->latest('id')
            ->take(10)
            ->get()
            ->map(fn(Invoice $invoice) => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => strtoupper($invoice->coin),
                'amount_coin' => (string)$invoice->amount_coin,
                'expected_usd' => (string)$invoice->expected_usd,
                'created_at' => $invoice->created_at->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'status' => $merchant->status,
                'fee_percent' => $merchant->fee_percent !== null ? (string)$merchant->fee_percent : null,
                'webhook_url' => $merchant->webhook_url,
                'has_webhook_secret' => !empty($merchant->webhook_secret),
                'created_at' => $merchant->created_at->toIso8601String(),
                'updated_at' => $merchant->updated_at->toIso8601String(),
                'merchant_users' => $merchant->users->map(fn($user) => [
                    'id' => $user->id,
                    'merchant_id' => $user->merchant_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_id' => $user->role_id,
                    'role_slug' => $user->role?->slug,
                    'role_name' => $user->role?->name,
                    'status' => $user->status,
                    'last_login_at' => optional($user->last_login_at)->toIso8601String(),
                    'created_at' => $user->created_at->toIso8601String(),
                ]),
                'wallet_summary' => [
                    'count' => $merchant->superWallets()->count(),
                ],
                'recent_invoices' => $recent_invoices,
            ]
        ]);
    }

    public function updateStatus(UpdateMerchantStatusRequest $request, Merchant $merchant): JsonResponse
    {
        $merchant->update(['status' => $request->validated('status')]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $merchant->id,
                'status' => $merchant->status,
                'updated_at' => $merchant->updated_at->toIso8601String(),
            ],
        ]);
    }
}
