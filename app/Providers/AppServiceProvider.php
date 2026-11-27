<?php

namespace App\Providers;

use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Paginator::useBootstrapFive();

        // The device id/name cookies are written by the reader's JS, so they
        // are plain text: decrypting them would blank them.
        \Illuminate\Cookie\Middleware\EncryptCookies::except(\App\Services\ReadingPositions::COOKIES);

        // Turbo compares the tracked <head> assets on every visit and does a
        // full page load when they change, so a tab left open across a deploy
        // picks up the new bundles instead of mixing two builds (e.g. two
        // copies of the reader module). Turbo only tracks <head> elements;
        // the attribute is inert on the reader tag @vite emits in the body.
        Vite::useScriptTagAttributes(['data-turbo-track' => 'reload']);
        Vite::useStyleTagAttributes(['data-turbo-track' => 'reload']);

        $this->bootGuard();
        $this->bootAccess();
    }

    /**
     * The `web` guard's driver: Laravel's session guard with per-browser
     * remember-me (App\Auth\BrowserGuard), built the way the framework
     * builds its own session guard.
     */
    private function bootGuard(): void
    {
        \Illuminate\Support\Facades\Auth::extend('novarr', function ($app, string $name, array $config) {
            $auth = $app['auth'];
            $guard = new \App\Auth\BrowserGuard(
                $name,
                $auth->createUserProvider($config['provider'] ?? null),
                $app['session.store'],
                rehashOnLogin: $app['config']->get('hashing.rehash_on_login', true),
                timeboxDuration: $app['config']->get('auth.timebox_duration', 200000),
                hashKey: $app['config']->get('app.key'),
            );
            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));
            if (isset($config['remember'])) {
                $guard->setRememberDuration($config['remember']);
            }

            return $guard;
        });
    }

    /**
     * Roles and rate limits of reader accounts (App\Support\AuthMode,
     * App\Support\RouteAccess, App\Http\Middleware\IdentifyReader).
     */
    private function bootAccess(): void
    {
        // The shell (layouts.app and the partials it includes) needs to know
        // who is signed in: the account menu, the offline queue's owner tag.
        // Computed per render, never shared at boot (Octane).
        \Illuminate\Support\Facades\View::composer('layouts.app', function (\Illuminate\View\View $view) {
            $reader = app(CurrentReader::class);
            $authOn = AuthMode::required();
            try {
                // Whose read state the page shows (null: nobody signed in).
                $readerId = $reader->isGuest() ? null : $reader->id();
                $ownerId = CurrentReader::ownerId();
            } catch (\Illuminate\Database\QueryException | \RuntimeException $e) {
                // No database / no owner yet (a page rendered without one).
                $readerId = $ownerId = null;
            }
            // Reader requests: a nav entry for everyone while the feature is
            // on; the admin (at home) also gets the pending count, one cached
            // query (App\Services\Requests\Requests::pendingCount()).
            $requestsOn = (bool) config('novarr.requests.enabled', true) && !($authOn && $reader->isGuest());
            $requestsPending = null;
            if ($requestsOn && Gate::allows('admin')) {
                try {
                    $requestsPending = \App\Services\Requests\Requests::pendingCount();
                } catch (\Illuminate\Database\QueryException $e) {
                    $requestsPending = null; // not migrated yet: no badge
                }
            }
            // The admin's "Needs you" count on Manage › Downloads: the same
            // lists as the Downloads page's first tab, cached for a minute.
            $downloadsAttention = null;
            if (Gate::allows('admin')) {
                try {
                    $downloadsAttention = (int) \Illuminate\Support\Facades\Cache::remember(
                        \App\Http\Controllers\DownloadController::NAV_ATTENTION_CACHE,
                        60,
                        fn() => \App\Http\Controllers\DownloadController::attentionTotal(),
                    );
                } catch (\Illuminate\Database\QueryException $e) {
                    $downloadsAttention = null; // not migrated yet: no badge
                }
            }
            $view->with([
                'requestsOn' => $requestsOn,
                'requestsPending' => $requestsPending,
                'downloadsAttention' => $downloadsAttention,
                'authOn' => $authOn,
                // The signed-in account (sign-in on), else null.
                'navUser' => $authOn && !$reader->isGuest() ? $reader->user() : null,
                'readerId' => $readerId,
                'readerIsOwner' => $readerId !== null && $readerId === $ownerId,
            ]);
        });

        // The one admin check (routes, controllers, Blade @can('admin')).
        // Sign-in off: everyone is the owner, so everyone is the admin.
        // Never on the public address (App\Support\PublicAddress): admin
        // features are home-network only, even for the admin.
        Gate::define('admin', function (?User $user = null) {
            if (\App\Support\PublicAddress::current()) {
                return false;
            }
            if (!AuthMode::required()) {
                return true;
            }

            return $user !== null && $user->isAdmin() && !$user->isDisabled();
        });

        // Rate limits (the limiter's cache store: file in production).
        // Device pairing: starting one is public, so per IP.
        RateLimiter::for('novarr-pairing-create', fn(Request $request) => Limit::perHour(10)->by('pair-create|' . $request->ip())
            ->response(fn() => response()->json(['message' => 'Too many link codes asked for. Try again later.', 'code' => 'throttled'], 429)));
        // Polling by secret: generous (every 3 s), per IP.
        RateLimiter::for('novarr-pairing-poll', fn(Request $request) => Limit::perMinute(60)->by('pair-poll|' . $request->ip()));
        // The second step and passkey sign-in (public), per IP: about load;
        // guessing is stopped by the challenge's 5 tries, the account's lock
        // and the public address's per-IP cap (App\Services\Credentials).
        RateLimiter::for('novarr-second-step', fn(Request $request) => Limit::perMinute(30)->by('second-step|' . $request->ip())
            ->response(fn(Request $request, array $headers) => \App\Http\Middleware\IdentifyReader::wantsJson($request)
                ? \App\Services\Credentials::tooManyJson((int) ($headers['Retry-After'] ?? 60))
                : response()->view('auth.forbidden', ['message' => \App\Services\Credentials::tooManyMessage((int) ($headers['Retry-After'] ?? 60)), 'title' => 'Too many attempts'], 429, $headers)));
        // Invite links (public): opening and accepting, per IP. A token is 64
        // hex characters, so this is about load, not guessing.
        RateLimiter::for('novarr-invite', fn(Request $request) => Limit::perMinute(30)->by('invite|' . $request->ip()));
        // "Stop these emails" (public, signed link), per IP: about load.
        RateLimiter::for('novarr-unsubscribe', fn(Request $request) => Limit::perMinute(30)->by('unsubscribe|' . $request->ip()));
        // Book uploads (each is up to NOVARR_BOOKS_MAX_UPLOAD_MB and is read in full): per account, else per address.
        RateLimiter::for('novarr-book-upload', fn(Request $request) => Limit::perHour(30)->by('book-upload|' . (app(\App\Support\CurrentReader::class)->isGuest() ? $request->ip() : app(\App\Support\CurrentReader::class)->user()->id)));
        // Starting an audiobook upload (each may be GBs, checked against the drive's free space): per account.
        // The chunk PUTs are not limited here (a 2 GB file is 256 of them); only their owner can send them.
        RateLimiter::for('novarr-audiobook-upload', fn(Request $request) => Limit::perHour(30)->by('audiobook-upload|' . (app(\App\Support\CurrentReader::class)->isGuest() ? $request->ip() : app(\App\Support\CurrentReader::class)->id()))
            ->response(fn() => response()->json(['message' => 'Too many uploads started. Wait a while and try again.', 'code' => 'throttled'], 429)));
        // Public file downloads (the Android app), per IP.
        RateLimiter::for('novarr-download', fn(Request $request) => Limit::perMinute(10)->by('download|' . $request->ip()));
        // Readers' "check for new chapters" / "build ePub": the admin is not limited.
        RateLimiter::for('novarr-reader-actions', function (Request $request) {
            $reader = app(CurrentReader::class);
            if (Gate::allows('admin')) {
                return Limit::none();
            }

            return Limit::perMinute(6)->by('reader-actions|' . ($reader->isGuest() ? $request->ip() : $reader->id()))
                ->response(fn() => response()->json(['message' => 'Too many requests. Wait a minute and try again.', 'code' => 'throttled'], 429));
        });

        // The Requests page's Discover shelf (GET /api/v1/requests/browse):
        // cheap when cached, but a cold page asks the source ~40 times.
        RateLimiter::for('novarr-request-browse', function (Request $request) {
            $reader = app(CurrentReader::class);
            if (Gate::allows('admin')) {
                return Limit::none();
            }

            return Limit::perMinute(30)->by('request-browse|' . ($reader->isGuest() ? $request->ip() : $reader->id()))
                ->response(fn() => response()->json(['message' => 'Too many requests. Wait a minute and try again.', 'code' => 'throttled'], 429));
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // One Fetcher per process so its HTTP clients (and their keep-alive
        // connections) are reused across chapter fetches. Tests swap it with
        // app()->instance().
        $this->app->singleton(\App\Scraping\Fetcher::class);

        // Whose read state this request / job uses: rebuilt per request
        // under Octane and per job in queue workers (scoped, never a singleton).
        $this->app->scoped(\App\Support\CurrentReader::class);

        // Audiobooks read their files through ffprobe / ffmpeg (tests bind a fake).
        $this->app->bind(\App\Audiobooks\AudioProbe::class, \App\Audiobooks\FfmpegProbe::class);

        // How a reader-request search asks its sources: lanes in parallel
        // PHP processes, or (parallelism 1, or no way to start a process)
        // one after another in this process. Tests bind the inline runner.
        $this->app->bind(\App\Services\Requests\SearchRunner::class, function () {
            $parallelism = \App\Services\Requests\RequestSearch::parallelism();
            $php = $parallelism > 1 ? \App\Services\Requests\ProcessSearchRunner::phpBinary() : null;

            return $php !== null
                ? new \App\Services\Requests\ProcessSearchRunner($parallelism, $php)
                : new \App\Services\Requests\InlineSearchRunner();
        });
    }
}
