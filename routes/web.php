<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/swagger', function () {
    return view('swagger', [
        'specUrl' => route('swagger.openapi', [], false),
    ]);
})->name('swagger.ui');

Route::get('/swagger/openapi.json', function () {
    $path = base_path('docs/api/openapi.json');

    abort_unless(is_file($path), 404, 'OpenAPI document is not generated. Run php artisan docs:generate-openapi.');

    return response()->file($path, [
        'Content-Type' => 'application/json',
    ]);
})->name('swagger.openapi');

Route::view('/architecture', 'architecture')->name('architecture');

Route::get('/i/{publicId}', [\App\Http\Controllers\HostedInvoiceController::class, 'show'])->name('hosted-invoice.show');
Route::get('/i/{publicId}/status', [\App\Http\Controllers\HostedInvoiceController::class, 'status'])->name('hosted-invoice.status');


Route::view('/admin/{path?}', 'admin')
    ->where('path', '.*')
    ->name('admin.portal');

Route::view('/merchant/{path?}', 'app')
    ->where('path', '.*')
    ->name('merchant.portal');
