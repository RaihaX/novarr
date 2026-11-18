<?php

use App\Http\Controllers\Api\V1 as ApiV1;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NovelChapterController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

/*
|--------------------------------------------------------------------------
| Health Check Routes (No Authentication Required)
|--------------------------------------------------------------------------
|
| These routes are used by Docker health checks and monitoring systems.
| They must be accessible without authentication.
|
*/
Route::get('/health', [HealthController::class, 'index']);
Route::get('/health/db', [HealthController::class, 'database']);
Route::get('/health/cache', [HealthController::class, 'cache']);
Route::get('/ping', [HealthController::class, 'ping']);

/*
|--------------------------------------------------------------------------
| Native app API (v1) — see the iOS app's API contract
|--------------------------------------------------------------------------
|
| No authentication, deliberately: a single-user app reached over the
| tailnet, like the web UI. The api group is stateless, so the POST routes
| need no CSRF token. The group's 60/min throttle is lifted here: the app
| prefetches hundreds of chapters at a time for offline reading.
|
*/
Route::prefix('v1')
    ->name('api.v1.')
    ->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class . ':60,1'])
    ->group(function () {
        // Conditional GET (ETag / 304) on the lists the app polls.
        $conditionalGet = 'cache.headers:private;max_age=0;etag';

        Route::get('/meta', [ApiV1\LibraryController::class, 'meta'])->name('meta');
        Route::get('/novels', [ApiV1\LibraryController::class, 'novels'])->middleware($conditionalGet)->name('novels');
        Route::get('/home', [ApiV1\LibraryController::class, 'home'])->name('home');
        Route::get('/novels/{id}', [ApiV1\LibraryController::class, 'show'])->whereNumber('id')->name('novels.show');
        Route::get('/novels/{id}/chapters', [ApiV1\LibraryController::class, 'chapters'])->whereNumber('id')->middleware($conditionalGet)->name('novels.chapters');
        Route::get('/novels/{id}/epub', [ApiV1\EpubController::class, 'show'])->whereNumber('id')->name('novels.epub');
        Route::post('/novels/{id}/epub', [ApiV1\EpubController::class, 'build'])->whereNumber('id')->name('novels.epub.build');
        // Where the series was read last, and on which device (the "go there?" prompt).
        Route::get('/novels/{id}/position', [ApiV1\PositionController::class, 'show'])->whereNumber('id')->name('novels.position');

        // Discover, add a novel, and "check for new chapters now" (the KOReader plugin).
        Route::get('/discover', [ApiV1\DiscoverController::class, 'index'])->name('discover');
        Route::get('/discover/browse', [ApiV1\DiscoverController::class, 'browse'])->name('discover.browse');
        Route::post('/discover/searches', [ApiV1\DiscoverSearchController::class, 'store'])->name('discover.searches.store');
        Route::get('/discover/searches/{id}', [ApiV1\DiscoverSearchController::class, 'show'])->name('discover.searches.show');
        Route::post('/novels', [ApiV1\AddNovelController::class, 'store'])->name('novels.store');
        Route::get('/novels/{id}/check', [ApiV1\CheckController::class, 'show'])->whereNumber('id')->name('novels.check');
        Route::post('/novels/{id}/check', [ApiV1\CheckController::class, 'start'])->whereNumber('id')->name('novels.check.start');

        Route::post('/chapters/bulk-read', [NovelChapterController::class, 'bulkRead'])->name('chapters.bulk_read');
        Route::get('/chapters/{id}', [ApiV1\ChapterController::class, 'show'])->whereNumber('id')->name('chapters.show');
        Route::get('/chapters/{id}/pages', [ApiV1\ChapterController::class, 'pages'])->whereNumber('id')->name('chapters.pages');
        Route::post('/chapters/{id}/progress', [NovelChapterController::class, 'progress'])->whereNumber('id')->name('chapters.progress');
        Route::post('/chapters/{id}/read', [NovelChapterController::class, 'setRead'])->whereNumber('id')->name('chapters.read');

        // Highlights: the website's bookmarks (excerpt + optional note on a chapter).
        Route::get('/highlights', [ApiV1\HighlightController::class, 'index'])->name('highlights');
        Route::post('/highlights', [ApiV1\HighlightController::class, 'store'])->name('highlights.store');
        Route::delete('/highlights/{id}', [ApiV1\HighlightController::class, 'destroy'])->whereNumber('id')->name('highlights.destroy');

        // The KOReader plugin's "update over Wi-Fi".
        Route::get('/koreader/plugin', [ApiV1\KoreaderPluginController::class, 'manifest'])->name('koreader.plugin');
        Route::get('/koreader/plugin/files/{path}', [ApiV1\KoreaderPluginController::class, 'file'])->where('path', '.*')->name('koreader.plugin.file');
    });
