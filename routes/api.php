<?php

use App\Http\Controllers\Api\AdminPortal\DashboardController;
use App\Http\Controllers\Api\AdminPortal\InvoiceController;
use App\Http\Controllers\Api\AdminPortal\MerchantApiKeyController;
use App\Http\Controllers\Api\AdminPortal\MerchantController;
use App\Http\Controllers\Api\AdminPortal\MerchantUserController;
use App\Http\Controllers\Api\AdminPortal\MerchantWalletController;
use App\Http\Controllers\Api\AdminPortal\WebhookDeliveryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Auth routes for admin and merchant */
Route::prefix('auth/merchant')->middleware('web')->group(function () {
    Route::post('/register', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'register'])->middleware('throttle:3,1');
    Route::post('/login', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'logout']);
    Route::get('/me', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'me'])->middleware('auth.merchant.portal');
});
Route::prefix('auth/admin')->middleware('web')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Api\Auth\AdminAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [\App\Http\Controllers\Api\Auth\AdminAuthController::class, 'logout'])->middleware('auth.admin');
    Route::get('/me', [\App\Http\Controllers\Api\Auth\AdminAuthController::class, 'me'])->middleware('auth.admin');
});

/** Functional routes for admin and merchant */
Route::prefix('admin')->middleware(['web', 'auth.admin'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->middleware('admin.capability:dashboard.read');

    Route::get('/merchants', [MerchantController::class, 'index'])->middleware('admin.capability:merchants.read');
    Route::post('/merchants', [MerchantController::class, 'store'])->middleware('admin.capability:merchants.create');
    Route::get('/merchants/{merchant}', [MerchantController::class, 'show'])->middleware('admin.capability:merchants.read');
    Route::patch('/merchants/{merchant}/status', [MerchantController::class, 'updateStatus'])->middleware('admin.capability:merchants.status.change');
    Route::get('/merchants/{merchant}/wallets', [MerchantWalletController::class, 'index'])->middleware('admin.capability:destination_wallets.read');
    Route::post('/merchants/{merchant}/wallets', [MerchantWalletController::class, 'store'])->middleware('admin.capability:destination_wallets.write');
    Route::put('/merchants/{merchant}/wallets/{wallet}', [MerchantWalletController::class, 'update'])->middleware('admin.capability:destination_wallets.write');
    Route::delete('/merchants/{merchant}/wallets/{wallet}', [MerchantWalletController::class, 'destroy'])->middleware('admin.capability:destination_wallets.write');

    Route::get('/merchant-users', [MerchantUserController::class, 'index'])->middleware('admin.capability:merchant_users.read');
    Route::post('/merchant-users', [MerchantUserController::class, 'store'])->middleware('admin.capability:merchant_users.create');
    Route::patch('/merchant-users/{merchantUser}/role', [MerchantUserController::class, 'updateRole'])->middleware('admin.capability:merchant_users.roles.write');
    Route::patch('/merchant-users/{merchantUser}/status', [MerchantUserController::class, 'updateStatus'])->middleware('admin.capability:merchant_users.status.change');

    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('admin.capability:invoices.read');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('admin.capability:invoices.read');
    Route::post('/invoices/{invoice}/refresh', [InvoiceController::class, 'refresh'])->middleware('admin.capability:invoices.refresh');

    Route::get('/webhook-deliveries', [WebhookDeliveryController::class, 'index'])->middleware('admin.capability:webhook_deliveries.read');
    Route::get('/webhook-deliveries/{delivery}', [WebhookDeliveryController::class, 'show'])->middleware('admin.capability:webhook_deliveries.read');
    Route::post('/webhook-deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry'])->middleware('admin.capability:webhook_deliveries.retry');

    Route::get('/merchant-api-keys', [MerchantApiKeyController::class, 'index'])->middleware('admin.capability:merchant_api_keys.read');
    Route::post('/merchant-api-keys/{apiKey}/revoke', [MerchantApiKeyController::class, 'revoke'])->middleware('admin.capability:merchant_api_keys.revoke');
});

Route::prefix('merchant')->middleware(['auth.merchant.portal', 'web', 'merchant.enabled'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\MerchantPortal\DashboardController::class, '__invoke'])->middleware('merchant.capability:portal.view');
    Route::get('/settings', [\App\Http\Controllers\Api\MerchantPortal\SettingsController::class, 'show'])->middleware('merchant.capability:portal.view');
    Route::put('/settings', [\App\Http\Controllers\Api\MerchantPortal\SettingsController::class, 'update'])->middleware('merchant.capability:invoices.write');

    Route::post('/invoices', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'store'])->middleware('merchant.capability:invoices.write');
    Route::get('/invoices/summary', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'summary'])->middleware('merchant.capability:invoices.read');
    Route::get('/invoices', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'index'])->middleware('merchant.capability:invoices.read');
    Route::get('/invoices/{id}', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'show'])->middleware('merchant.capability:invoices.read');
    Route::post('/invoices/{id}/refresh', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'refresh'])->middleware('merchant.capability:invoices.read');

    Route::get('/balances', [\App\Http\Controllers\Api\MerchantPortal\BalanceController::class, '__invoke'])->middleware('merchant.capability:balances.read');
    Route::get('/settlement-entries', [\App\Http\Controllers\Api\MerchantPortal\SettlementEntryController::class, 'index'])->middleware('merchant.capability:balances.read');
    Route::get('/settlement-policies', [\App\Http\Controllers\Api\MerchantPortal\SettlementPolicyController::class, 'index'])->middleware('merchant.capability:settlements.read');
    Route::put('/settlement-policies/{assetKey}', [\App\Http\Controllers\Api\MerchantPortal\SettlementPolicyController::class, 'update'])->middleware('merchant.capability:settlements.write');

    Route::get('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'index'])->middleware('merchant.capability:wallets.read');
    Route::post('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'store'])->middleware('merchant.capability:wallets.write');
    Route::put('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'update'])->middleware('merchant.capability:wallets.write');
    Route::delete('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'destroy'])->middleware('merchant.capability:wallets.write');

    Route::get('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'settings'])->middleware('merchant.capability:webhooks.read');
    Route::put('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'updateSettings'])->middleware('merchant.capability:webhooks.write');
    Route::post('/webhook-deliveries/test', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'sendTest'])->middleware('merchant.capability:webhooks.write');
    Route::get('/webhook-deliveries', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'deliveries'])->middleware('merchant.capability:webhooks.read');
    Route::get('/webhook-deliveries/{delivery}', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'deliveryDetail'])->middleware('merchant.capability:webhooks.read');
    Route::post('/webhook-deliveries/{delivery}/retry', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'retryDelivery'])->middleware('merchant.capability:webhooks.write');

    Route::get('/api-keys', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'index'])->middleware('merchant.capability:api_keys.read');
    Route::post('/api-keys', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'store'])->middleware('merchant.capability:api_keys.write');
    Route::delete('/api-keys/{id}', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'destroy'])->middleware('merchant.capability:api_keys.write');

    Route::get('/merchant-users', [\App\Http\Controllers\Api\MerchantPortal\MerchantUserController::class, 'index'])->middleware('merchant.capability:merchant_users.read');
    Route::get('/merchant-users/{merchantUser}', [\App\Http\Controllers\Api\MerchantPortal\MerchantUserController::class, 'show'])->middleware('merchant.capability:merchant_users.read');
    Route::post('/merchant-users', [\App\Http\Controllers\Api\MerchantPortal\MerchantUserController::class, 'store'])->middleware('merchant.capability:merchant_users.write');
    Route::patch('/merchant-users/{merchantUser}/role', [\App\Http\Controllers\Api\MerchantPortal\MerchantUserController::class, 'updateRole'])->middleware('merchant.capability:merchant_users.write');
    Route::patch('/merchant-users/{merchantUser}/status', [\App\Http\Controllers\Api\MerchantPortal\MerchantUserController::class, 'updateStatus'])->middleware('merchant.capability:merchant_users.write');
    Route::delete('/merchant-users/{merchantUser}', [\App\Http\Controllers\Api\MerchantPortal\MerchantUserController::class, 'destroy'])->middleware('merchant.capability:merchant_users.write');
});

Route::prefix('v1')->middleware(['auth.merchant', 'throttle:120,1'])->group(function () {
    Route::post('/invoices', [\App\Http\Controllers\Api\InvoiceController::class, 'store']);
    Route::get('/invoices/{id}', [\App\Http\Controllers\Api\InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/refresh', [\App\Http\Controllers\Api\InvoiceRefreshController::class, '__invoke']);
});

if (config('app.test_webhook_routes_enabled') && ! app()->isProduction()) {
    Route::post('/test/webhook-receiver', function (Request $request) {
        Log::info('test webhook received', [
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            'json' => $request->all(),
        ]);

        return response()->json(['ok' => true]);
    });

    Route::post('/test/webhook-fail', function (Request $request) {
        Log::info('test webhook fail receiver', [
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            'json' => $request->all(),
        ]);

        return response()->json(['ok' => false], 500);
    });
}
