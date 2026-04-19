<?php

use App\Http\Controllers\Api\AdminPortal\InvoiceController;
use App\Http\Controllers\Api\AdminPortal\MerchantApiKeyController;
use App\Http\Controllers\Api\AdminPortal\MerchantController;
use App\Http\Controllers\Api\AdminPortal\MerchantUserController;
use App\Http\Controllers\Api\AdminPortal\WebhookDeliveryController;
use App\Http\Controllers\Api\AdminPortal\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
/** Auth routes for admin and merchant */
Route::prefix('auth/merchant')->middleware('web')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'logout'])->middleware('auth.merchant.portal');
    Route::get('/me', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'me'])->middleware('auth.merchant.portal');
});
Route::prefix('auth/admin')->middleware('web')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Api\Auth\AdminAuthController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\Api\Auth\AdminAuthController::class, 'logout'])->middleware('auth.admin');
    Route::get('/me', [\App\Http\Controllers\Api\Auth\AdminAuthController::class, 'me'])->middleware('auth.admin');
});

/** Functional routes for admin and merchant */
Route::prefix('admin')->middleware(['web', 'auth.admin'])->group(function () {
    Route::get('/dashboard', DashboardController::class);

    Route::get('/merchants', [MerchantController::class, 'index']);
    Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);
    Route::patch('/merchants/{merchant}/status', [MerchantController::class, 'updateStatus']);

    Route::get('/merchant-users', [MerchantUserController::class, 'index']);
    Route::post('/merchant-users', [MerchantUserController::class, 'store']);
    Route::patch('/merchant-users/{merchantUser}/role', [MerchantUserController::class, 'updateRole']);
    Route::patch('/merchant-users/{merchantUser}/status', [MerchantUserController::class, 'updateStatus']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{invoice}/refresh', [InvoiceController::class, 'refresh']);

    Route::get('/webhook-deliveries', [WebhookDeliveryController::class, 'index']);
    Route::get('/webhook-deliveries/{delivery}', [WebhookDeliveryController::class, 'show']);
    Route::post('/webhook-deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry']);

    Route::get('/merchant-api-keys', [MerchantApiKeyController::class, 'index']);
    Route::post('/merchant-api-keys/{apiKey}/revoke', [MerchantApiKeyController::class, 'revoke']);
});

Route::prefix('merchant')->middleware(['auth.merchant.portal', 'web', 'merchant.enabled'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\MerchantPortal\DashboardController::class, '__invoke'])->middleware('merchant.capability:portal.view');

    Route::post('/invoices', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'store'])->middleware('merchant.capability:invoices.read');
    Route::get('/invoices', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'index'])->middleware('merchant.capability:invoices.read');
    Route::get('/invoices/{id}', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'show'])->middleware('merchant.capability:invoices.read');
    Route::post('/invoices/{id}/refresh', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'refresh'])->middleware('merchant.capability:invoices.read');

    Route::get('/balances', [\App\Http\Controllers\Api\MerchantPortal\BalanceController::class, '__invoke'])->middleware('merchant.capability:balances.read');

    Route::get('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'index'])->middleware('merchant.capability:wallets.read');
    Route::post('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'store'])->middleware('merchant.capability:wallets.write');
    Route::put('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'update'])->middleware('merchant.capability:wallets.write');
    Route::delete('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'destroy'])->middleware('merchant.capability:wallets.write');

    Route::get('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'settings'])->middleware('merchant.capability:webhooks.read');
    Route::put('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'updateSettings'])->middleware('merchant.capability:webhooks.write');
    Route::get('/webhook-deliveries', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'deliveries'])->middleware('merchant.capability:webhooks.read');
    Route::get('/webhook-deliveries/{delivery}', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'deliveryDetail'])->middleware('merchant.capability:webhooks.read');

    Route::get('/api-keys', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'index'])->middleware('merchant.capability:api_keys.read');
    Route::post('/api-keys', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'store'])->middleware('merchant.capability:api_keys.write');
    Route::delete('/api-keys/{id}', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'destroy'])->middleware('merchant.capability:api_keys.write');
});

Route::prefix('v1')->middleware('auth.merchant')->group(function () {
    Route::post('/invoices', [\App\Http\Controllers\Api\InvoiceController::class, 'store']);
    Route::get('/invoices/{id}', [\App\Http\Controllers\Api\InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/refresh', [\App\Http\Controllers\Api\InvoiceRefreshController::class, '__invoke']);
});


// TEST ROUTES FOR TESTING WEBHOOKS
// TODO: Delete them when not needed
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
