<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'is_director' => \App\Http\Middleware\isDirector::class,
            'is_marketing' => \App\Http\Middleware\isMarketing::class,
            'is_finance' => \App\Http\Middleware\isFinance::class,
            'is_service' => \App\Http\Middleware\isService::class,
            'is_inventory' => \App\Http\Middleware\isInventory::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
