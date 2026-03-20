<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth/merchant')->middleware('web')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'logout'])->middleware('auth.merchant.portal');
    Route::get('/me', [\App\Http\Controllers\Api\Auth\MerchantAuthController::class, 'me'])->middleware('auth.merchant.portal');
});

Route::prefix('merchant')->middleware(['auth.merchant.portal', 'web'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\MerchantPortal\DashboardController::class, '__invoke']);

    Route::get('/invoices', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [\App\Http\Controllers\Api\MerchantPortal\InvoiceController::class, 'show']);

    Route::get('/balances', [\App\Http\Controllers\Api\MerchantPortal\BalanceController::class, '__invoke']);

    Route::get('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'index']);
    Route::post('/wallets', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'store']);
    Route::put('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'update']);
    Route::delete('/wallets/{id}', [\App\Http\Controllers\Api\MerchantPortal\WalletController::class, 'destroy']);

    Route::get('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'settings']);
    Route::put('/webhook-settings', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'updateSettings']);
    Route::get('/webhook-deliveries', [\App\Http\Controllers\Api\MerchantPortal\WebhookController::class, 'deliveries']);

    Route::get('/api-keys', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'index']);
    Route::post('/api-keys', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{id}', [\App\Http\Controllers\Api\MerchantPortal\ApiKeyController::class, 'destroy']);
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
