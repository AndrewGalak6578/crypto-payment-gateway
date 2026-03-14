<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::prefix('v1')->middleware('auth.merchant')->group(function () {
    Route::post('/invoices', [\App\Http\Controllers\Api\InvoiceController::class, 'store']);
    Route::get('/invoices/{id}', [\App\Http\Controllers\Api\InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/refresh', [\App\Http\Controllers\Api\InvoiceRefreshController::class, '__invoke']);
});

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
