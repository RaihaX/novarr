<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Route caching is automatically loaded when available.
            // In production, run: php artisan route:cache
            // To clear: php artisan route:clear
            // The cached routes file is stored at bootstrap/cache/routes-v7.php
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The ONE CSRF exception list, applied by the framework's
        // ValidateCsrfToken in the default web group (the custom
        // App\Http\Middleware\VerifyCsrfToken is no longer registered).
        // Read-state endpoints are replayed from the offline sync queue, where
        // a session CSRF token may have rotated. Single-user app behind
        // Tailscale with no auth, so the tokenless replay is acceptable.
        $middleware->validateCsrfTokens(except: [
            'chapters/*/toggle-read',
            'chapters/*/read-through',
            'chapters/*/progress',
            'chapters/bulk-read',
        ]);

        // Global middleware. Laravel 11's default global stack already runs
        // TrustProxies, PreventRequestsDuringMaintenance, ValidatePostSize,
        // TrimStrings and ConvertEmptyStringsToNull; appending our own copies
        // ran each twice per request. Configure the framework's instead.
        $middleware->trimStrings(except: ['password', 'password_confirmation']);
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->append(\App\Http\Middleware\PerformanceMonitoring::class);

        // Route middleware priority for optimal performance
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // The framework's default `web` group already runs EncryptCookies,
        // AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession,
        // ValidateCsrfToken and SubstituteBindings; appending them again ran
        // each one twice per request, so the web group is left as-is. Same
        // for SubstituteBindings in the `api` group.
        $middleware->api(append: [
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':60,1',
        ]);

        // Route middleware aliases
        // Single-user app with no auth — no auth/guest middleware aliases.
        $middleware->alias([
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
