<?php

use App\Http\Middleware\AuthenticateSource;
use App\Http\Middleware\RestrictToLocal;
use App\Http\Middleware\ThrottleIngestSource;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'source.auth' => AuthenticateSource::class,
            'source.throttle' => ThrottleIngestSource::class,
        ]);

        // Semua route web (dashboard, Aturan, /data/*) hanya untuk alamat yang diizinkan.
        // Diperlukan karena server dibuka ke jaringan (--host=0.0.0.0) agar ESP32 dapat mengirim data.
        $middleware->web(append: RestrictToLocal::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn(Request $request) => $request->is('api/*'));
    })->create();
