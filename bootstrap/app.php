<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.merchant' => \App\Http\Middleware\AuthMerchantApiKey::class,
            'auth.merchant.portal' => \App\Http\Middleware\AuthenticateMerchant::class,
            'merchant.capability' => \App\Http\Middleware\EnsureMerchantCapability::class,
            'auth.admin' => \App\Http\Middleware\AuthenticateAdmin::class,
            'merchant.enabled' => \App\Http\Middleware\CheckMerchantEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
