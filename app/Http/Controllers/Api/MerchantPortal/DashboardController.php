<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\MerchantPortal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MerchantBalance;
use App\Models\MerchantUser;
use App\Models\SuperWallet;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var MerchantUser $merchantUser */
        $merchantUser = $request->attributes->get('merchant_user');
        $merchantId = $merchantUser->merchant_id;

        $balances = MerchantBalance::query()
            ->where('merchant_id', $merchantId)
            ->orderBy('coin')
            ->get(['coin', 'amount'])
            ->map(fn(MerchantBalance $balance) => [
                'coin' => strtoupper($balance->coin),
                'amount' => (string)$balance->amount,
            ]);

        $recentInvoices = Invoice::query()
            ->where('merchant_id', $merchantId)
            ->latest('id')
            ->limit(10)
            ->get(['id',
                'public_id',
                'external_id',
                'status',
                'coin',
                'amount_coin',
                'expected_usd',
                'received_conf_coin',
                'forward_status',
                'created_at'])
            ->map(fn(Invoice $invoice) => [
                'id' => $invoice->id,
                'public_id' => $invoice->public_id,
                'external_id' => $invoice->external_id,
                'status' => $invoice->status,
                'coin' => strtoupper($invoice->coin),
                'amount_coin' => (string)$invoice->amount_coin,
                'expected_usd' => (string)$invoice->expected_usd,
                'received_conf_coin' => (string)$invoice->received_conf_coin,
                'forward_status' => $invoice->forward_status,
                'created_at' => $invoice->created_at->toIso8601String(),
            ]);

        $wallets = SuperWallet::query()
            ->where('merchant_id', $merchantId)
            ->orderBy('coin')
            ->get(['id', 'coin', 'wallet', 'fee_rate'])
            ->map(fn (SuperWallet $wallet) => [
                'id' => $wallet->id,
                'coin' => strtoupper($wallet->coin),
                'wallet' => $wallet->wallet,
                'fee_rate' => $wallet->fee_rate !== null ? (string)$wallet->fee_rate : null,
            ]);

        $failedWebhookCount = WebhookDelivery::query()
            ->whereHas('invoice', fn ($q) => $q->where('merchant_id', $merchantId))
            ->where('status', 'failed')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'paid_invoices_count' => Invoice::query()
                        ->where('merchant_id', $merchantId)
                        ->where('status', 'paid')
                        ->count(),
                    'pending_invoices_count' => Invoice::query()
                        ->where('merchant_id', $merchantId)
                        ->whereIn('status', ['pending', 'fixated'])
                        ->count(),
                    'failed_webhook_deliveries_count' => $failedWebhookCount,
                ],
                'balances' => $balances,
                'recent_invoices' => $recentInvoices,
                'wallets' => $wallets,
            ],
        ]);
    }
}
