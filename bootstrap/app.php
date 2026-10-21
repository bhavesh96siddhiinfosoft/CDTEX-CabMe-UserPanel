<?php

use App\Http\Middleware\EnsureRider;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\NoHtmlCache;
use App\Http\Middleware\RedirectIfRider;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters: SetLocale first so the maintenance notice is
        // translated, and NoHtmlCache last because every document here is
        // session-specific — a page held from before a deploy keeps running
        // the old markup and stylesheet.
        $middleware->web(append: [SetLocale::class, MaintenanceMode::class, NoHtmlCache::class]);

        // Gateway webhooks are server-to-server: no session, no CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'rider' => EnsureRider::class,
            'guest.rider' => RedirectIfRider::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
