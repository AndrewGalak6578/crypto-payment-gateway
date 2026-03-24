<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/i/{publicId}', [\App\Http\Controllers\HostedInvoiceController::class, 'show'])->name('hosted-invoice.show');
Route::get('/i/{publicId}/status', [\App\Http\Controllers\HostedInvoiceController::class, 'status'])->name('hosted-invoice.status');


Route::view('/admin/{path?}', 'admin')
    ->where('path', '.*')
    ->name('admin.portal');

Route::view('/merchant/{path?}', 'app')
    ->where('path', '.*')
    ->name('merchant.portal');
