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
            // Highlights saved offline are replayed from the queue later,
            // when the page's token may no longer match the session.
            'bookmarks',
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

        // Reader accounts: who is calling and may they call this route
        // (App\Support\RouteAccess). Last in both groups, after the session
        // has started. The API group may borrow the web session for the web
        // UI's own calls (ApiSession) — only with sign-in on.
        $middleware->web(append: [
            \App\Http\Middleware\IdentifyReader::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\ApiSession::class,
            \App\Http\Middleware\IdentifyReader::class,
        ]);

        // Route middleware aliases. Auth is App\Http\Middleware\IdentifyReader
        // on the whole web and api groups, not per-route aliases.
        // cache.headers: the framework's, with the reader in the ETag.
        $middleware->alias([
            'cache.headers' => \App\Http\Middleware\SetCacheHeaders::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The native app's API answers errors (404, 422) as JSON whatever
        // Accept header the client sends.
        $exceptions->shouldRenderJsonWhen(
            fn(\Illuminate\Http\Request $request) => $request->is('api/v1/*') || $request->expectsJson()
        );

        // Not signed in / not allowed, thrown from inside a request (a guest
        // reaching read state, Gate::authorize('admin')): the same answers as
        // App\Http\Middleware\IdentifyReader gives.
        $exceptions->render(fn(\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request)
            => \App\Http\Middleware\IdentifyReader::unauthenticated($request));
        // A Gate denial reaches the callbacks as AccessDeniedHttpException
        // wrapping the AuthorizationException; other 403s render as before.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request) {
            if (!$e->getPrevious() instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return null;
            }

            return \App\Http\Middleware\IdentifyReader::forbidden($request);
        });
    })
    ->create();
