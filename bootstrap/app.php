<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: [
            __DIR__.'/../routes/api.php',
            // طبقة الجوال المعزولة — ملف جديد بالكامل تحت /api/mobile.
            // تُحمَّل ضمن مجموعة api (تُسجَّل قبل web) كي لا يبتلعها catch-all الـSPA.
            __DIR__.'/../routes/mobile.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth'        => \Illuminate\Auth\Middleware\Authenticate::class,
            'permission'  => \App\Http\Middleware\CheckPermission::class,
            'active'      => \App\Http\Middleware\EnsureUserIsActive::class,
            'mobile_role' => \App\Http\Middleware\EnsureMobileRole::class,
            'cache.api'   => \App\Http\Middleware\CacheApiResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
