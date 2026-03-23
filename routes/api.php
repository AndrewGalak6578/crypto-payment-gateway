<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth/merchant')->middleware('web')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'logout'])->middleware('auth.merchant.portal');
    Route::get('/me', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'me'])->middleware('auth.merchant.portal');
});

Route::prefix('merchant')->middleware(['auth.merchant.portal', 'web'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\MerchantPortal\DashboardController::class, '__invoke'])->middleware('merchant.capability:portal.view');

    Route::get('/invoices', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'index'])->middleware('merchant.capability:invoices.read');
    Route::get('/invoices/{id}', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'show'])->middleware('merchant.capability:invoices.read');

    Route::get('/balances', [\App\Http\Controllers\Api\MerchantPortal\BalanceController::class, '__invoke'])->middleware('merchant.capability:balances.read');

    Route::get('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'index'])->middleware('merchant.capability:wallets.read');
    Route::post('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'store'])->middleware('merchant.capability:wallets.write');
    Route::put('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'update'])->middleware('merchant.capability:wallets.write');
    Route::delete('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'destroy'])->middleware('merchant.capability:wallets.write');

    Route::get('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'settings'])->middleware('merchant.capability:webhooks.read');
    Route::put('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'updateSettings'])->middleware('merchant.capability:webhooks.write');
    Route::get('/webhook-deliveries', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'deliveries'])->middleware('merchant.capability:webhooks.read');

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
