<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
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

        // Turbo compares the tracked <head> assets on every visit and does a
        // full page load when they change, so a tab left open across a deploy
        // picks up the new bundles instead of mixing two builds (e.g. two
        // copies of the reader module). Turbo only tracks <head> elements;
        // the attribute is inert on the reader tag @vite emits in the body.
        Vite::useScriptTagAttributes(['data-turbo-track' => 'reload']);
        Vite::useStyleTagAttributes(['data-turbo-track' => 'reload']);
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
    }
}
