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

        Route::post('/chapters/bulk-read', [NovelChapterController::class, 'bulkRead'])->name('chapters.bulk_read');
        Route::get('/chapters/{id}', [ApiV1\ChapterController::class, 'show'])->whereNumber('id')->name('chapters.show');
        Route::post('/chapters/{id}/progress', [NovelChapterController::class, 'progress'])->whereNumber('id')->name('chapters.progress');
        Route::post('/chapters/{id}/read', [NovelChapterController::class, 'setRead'])->whereNumber('id')->name('chapters.read');
    });
