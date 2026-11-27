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
Route::get('/health', [HealthController::class, 'index'])->name('api.health');
Route::get('/health/db', [HealthController::class, 'database'])->name('api.health.db');
Route::get('/health/cache', [HealthController::class, 'cache'])->name('api.health.cache');
Route::get('/ping', [HealthController::class, 'ping'])->name('api.ping');

/*
|--------------------------------------------------------------------------
| Native app API (v1) — see the iOS app's API contract
|--------------------------------------------------------------------------
|
| Who may call what is App\Support\RouteAccess (enforced by the api group's
| App\Http\Middleware\IdentifyReader when "Require sign-in" is on: bearer
| token, or the web session for the web UI's own calls). With sign-in off
| every call is the owner. The api group is stateless, so the POST routes
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

        // Sign-in (auth contract): password → token, sign out, who am I, and
        // linking a device by code (the KOReader plugin).
        Route::post('/auth/token', [ApiV1\AuthController::class, 'store'])->name('auth.token.store');
        // The second step after a password on the public address (App\Services\SecondStep).
        Route::post('/auth/token/email-code', [ApiV1\AuthController::class, 'emailCode'])->middleware('throttle:novarr-second-step')->name('auth.token.email_code');
        Route::post('/auth/token/second-step', [ApiV1\AuthController::class, 'secondStep'])->middleware('throttle:novarr-second-step')->name('auth.token.second_step');
        Route::delete('/auth/token', [ApiV1\AuthController::class, 'destroy'])->name('auth.token.destroy');
        Route::get('/auth/me', [ApiV1\AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/pairings', [ApiV1\PairingController::class, 'store'])->middleware('throttle:novarr-pairing-create')->name('auth.pairings.store');
        Route::get('/auth/pairings/{secret}', [ApiV1\PairingController::class, 'show'])->middleware('throttle:novarr-pairing-poll')->name('auth.pairings.show');
        Route::get('/novels', [ApiV1\LibraryController::class, 'novels'])->middleware($conditionalGet)->name('novels');
        Route::get('/home', [ApiV1\LibraryController::class, 'home'])->name('home');
        // The reader's own stats across reading and listening (App\Services\ReaderStats).
        Route::get('/stats', [ApiV1\StatsController::class, 'index'])->name('stats');
        Route::get('/novels/{id}', [ApiV1\LibraryController::class, 'show'])->whereNumber('id')->name('novels.show');
        Route::get('/novels/{id}/chapters', [ApiV1\LibraryController::class, 'chapters'])->whereNumber('id')->middleware($conditionalGet)->name('novels.chapters');
        Route::get('/novels/{id}/epub', [ApiV1\EpubController::class, 'show'])->whereNumber('id')->name('novels.epub');
        Route::post('/novels/{id}/epub', [ApiV1\EpubController::class, 'build'])->whereNumber('id')->middleware('throttle:novarr-reader-actions')->name('novels.epub.build');
        // "Ask for the audiobook" (the apps; the web has its own form route).
        Route::post('/novels/{id}/audiobook/ask', [\App\Http\Controllers\DownloadController::class, 'audiobookAskApi'])->whereNumber('id')->name('novels.audiobook_ask');
        // "Send to my Kindle / email" (App\Services\SendEpub): start, and its progress.
        Route::get('/novels/{id}/send', [ApiV1\SendEpubController::class, 'show'])->whereNumber('id')->name('novels.send.show');
        Route::post('/novels/{id}/send', [ApiV1\SendEpubController::class, 'store'])->whereNumber('id')->name('novels.send');
        // Where the series was read last, and on which device (the "go there?" prompt).
        Route::get('/novels/{id}/position', [ApiV1\PositionController::class, 'show'])->whereNumber('id')->name('novels.position');

        // Discover, add a novel, and "check for new chapters now" (the KOReader plugin).
        Route::get('/discover', [ApiV1\DiscoverController::class, 'index'])->name('discover');
        Route::get('/discover/browse', [ApiV1\DiscoverController::class, 'browse'])->name('discover.browse');
        Route::post('/discover/searches', [ApiV1\DiscoverSearchController::class, 'store'])->name('discover.searches.store');
        Route::get('/discover/searches/{id}', [ApiV1\DiscoverSearchController::class, 'show'])->name('discover.searches.show');
        Route::post('/novels', [ApiV1\AddNovelController::class, 'store'])->name('novels.store');
        Route::get('/novels/{id}/check', [ApiV1\CheckController::class, 'show'])->whereNumber('id')->name('novels.check');
        Route::post('/novels/{id}/check', [ApiV1\CheckController::class, 'start'])->whereNumber('id')->middleware('throttle:novarr-reader-actions')->name('novels.check.start');

        // Whole series read / unread at once (the apps' multi-select).
        Route::post('/novels/bulk-read', [ApiV1\NovelBulkController::class, 'read'])->name('novels.bulk_read');
        Route::post('/chapters/bulk-read', [NovelChapterController::class, 'bulkRead'])->name('chapters.bulk_read');
        Route::get('/chapters/{id}', [ApiV1\ChapterController::class, 'show'])->whereNumber('id')->name('chapters.show');
        Route::get('/chapters/{id}/pages', [ApiV1\ChapterController::class, 'pages'])->whereNumber('id')->name('chapters.pages');
        // A manga chapter as a CBZ (the KOReader plugin: KOReader opens comic archives).
        Route::get('/chapters/{id}/cbz', [ApiV1\MangaCbzController::class, 'show'])->whereNumber('id')->name('chapters.cbz');
        Route::post('/chapters/{id}/progress', [NovelChapterController::class, 'progress'])->whereNumber('id')->name('chapters.progress');
        Route::post('/chapters/{id}/read', [NovelChapterController::class, 'setRead'])->whereNumber('id')->name('chapters.read');

        // Highlights: the website's bookmarks (excerpt + optional note on a chapter).
        Route::get('/highlights', [ApiV1\HighlightController::class, 'index'])->name('highlights');
        Route::post('/highlights', [ApiV1\HighlightController::class, 'store'])->name('highlights.store');
        Route::delete('/highlights/{id}', [ApiV1\HighlightController::class, 'destroy'])->whereNumber('id')->name('highlights.destroy');

        // Reading lists: the reader's own named groups of series (App\Services\ReadingLists).
        // Writes are unthrottled like the highlights' (cheap, own rows, capped per reader).
        Route::get('/lists', [ApiV1\ReadingListController::class, 'index'])->name('lists');
        Route::post('/lists', [ApiV1\ReadingListController::class, 'store'])->name('lists.store');
        // Categories: the reader's order, and many series in / out of many lists at once.
        Route::patch('/lists/order', [ApiV1\ReadingListController::class, 'order'])->name('lists.order');
        Route::put('/lists/memberships', [ApiV1\ReadingListController::class, 'memberships'])->name('lists.memberships');
        Route::patch('/lists/{list}', [ApiV1\ReadingListController::class, 'update'])->whereNumber('list')->name('lists.update');
        Route::delete('/lists/{list}', [ApiV1\ReadingListController::class, 'destroy'])->whereNumber('list')->name('lists.destroy');
        Route::put('/lists/{list}/novels/{novel}', [ApiV1\ReadingListController::class, 'addNovel'])->whereNumber(['list', 'novel'])->name('lists.novels.add');
        Route::delete('/lists/{list}/novels/{novel}', [ApiV1\ReadingListController::class, 'removeNovel'])->whereNumber(['list', 'novel'])->name('lists.novels.remove');
        // "Want to read": the built-in list, by series (made on first use).
        Route::get('/want-to-read', [ApiV1\ReadingListController::class, 'wantToRead'])->name('want_to_read');
        Route::put('/novels/{novel}/want-to-read', [ApiV1\ReadingListController::class, 'addWantToRead'])->whereNumber('novel')->name('novels.want_to_read.add');
        Route::delete('/novels/{novel}/want-to-read', [ApiV1\ReadingListController::class, 'removeWantToRead'])->whereNumber('novel')->name('novels.want_to_read.remove');
        // "Favourites": the second built-in list (the heart), by series (made on first use).
        Route::get('/favourites', [ApiV1\ReadingListController::class, 'favourites'])->name('favourites');
        Route::put('/novels/{novel}/favourite', [ApiV1\ReadingListController::class, 'addFavourite'])->whereNumber('novel')->name('novels.favourite.add');
        Route::delete('/novels/{novel}/favourite', [ApiV1\ReadingListController::class, 'removeFavourite'])->whereNumber('novel')->name('novels.favourite.remove');

        // Reader requests: search every source of a medium, request a series,
        // the owner approves / declines (App\Services\Requests). Searching,
        // requesting and the notification feed are reader routes; deciding is
        // the admin's (home network only).
        Route::post('/requests/searches', [ApiV1\RequestSearchController::class, 'store'])->name('requests.searches.store');
        Route::get('/requests/searches/{id}', [ApiV1\RequestSearchController::class, 'show'])->name('requests.searches.show');
        // The Discover shelf: popular / new & popular / top-rated charts to request from.
        Route::get('/requests/browse', [ApiV1\RequestBrowseController::class, 'index'])->middleware('throttle:novarr-request-browse')->name('requests.browse');
        Route::get('/requests', [ApiV1\RequestController::class, 'index'])->name('requests');
        Route::post('/requests', [ApiV1\RequestController::class, 'store'])->middleware('throttle:novarr-reader-actions')->name('requests.store');
        Route::get('/requests/{id}', [ApiV1\RequestController::class, 'show'])->whereNumber('id')->name('requests.show');
        // The categories the caller wants the series in once it is available (App\Services\Requests\RequestCategories).
        Route::put('/requests/{id}/categories', [ApiV1\RequestController::class, 'categories'])->whereNumber('id')->name('requests.categories');
        Route::delete('/requests/{id}', [ApiV1\RequestController::class, 'destroy'])->whereNumber('id')->name('requests.destroy');
        Route::post('/requests/{id}/approve', [ApiV1\RequestController::class, 'approve'])->whereNumber('id')->name('requests.approve');
        Route::post('/requests/{id}/decline', [ApiV1\RequestController::class, 'decline'])->whereNumber('id')->name('requests.decline');
        Route::post('/requests/{id}/rerank', [ApiV1\RequestController::class, 'rerank'])->whereNumber('id')->name('requests.rerank');
        Route::get('/notifications', [ApiV1\NotificationController::class, 'index'])->name('notifications');
        // New chapters to read in series the reader follows (one combined phone notification).
        Route::get('/updates', [ApiV1\UpdatesController::class, 'index'])->name('updates');

        // Books: the app's "Share to Novarr" (reader), and the books email
        // address, posted by the Cloudflare Email Worker (shared secret).
        Route::post('/books/uploads', [ApiV1\BookUploadController::class, 'store'])->middleware('throttle:novarr-reader-actions')->name('books.uploads.store');
        Route::post('/books/inbox', [ApiV1\BookInboxController::class, 'store'])->middleware('throttle:30,1')->name('books.inbox');

        // Audiobooks (App\Audiobooks; the audiobooks contract): listening is
        // the reader's, the inbox / match / scan the owner's. A part streams
        // with HTTP Range; its URL in the detail is signed for the reader
        // (RouteAccess::SIGNED_ROUTES) so <audio> and ExoPlayer need no headers.
        Route::get('/audiobooks', [ApiV1\AudiobookController::class, 'index'])->name('audiobooks');
        Route::get('/audiobooks/continue', [ApiV1\AudiobookController::class, 'continue'])->name('audiobooks.continue');
        Route::get('/audiobooks/inbox', [ApiV1\AudiobookController::class, 'inbox'])->name('audiobooks.inbox');
        Route::post('/audiobooks/scan', [ApiV1\AudiobookController::class, 'scan'])->middleware('throttle:novarr-reader-actions')->name('audiobooks.scan');
        Route::get('/audiobooks/{id}', [ApiV1\AudiobookController::class, 'show'])->whereNumber('id')->name('audiobooks.show');
        Route::get('/audiobooks/{id}/parts/{index}', [ApiV1\AudiobookController::class, 'part'])->whereNumber(['id', 'index'])->name('audiobooks.parts.show');
        Route::put('/audiobooks/{id}/progress', [ApiV1\AudiobookController::class, 'saveProgress'])->whereNumber('id')->name('audiobooks.progress');
        Route::post('/audiobooks/{id}/bookmarks', [ApiV1\AudiobookController::class, 'storeBookmark'])->whereNumber('id')->name('audiobooks.bookmarks.store');
        Route::delete('/audiobooks/{id}/bookmarks/{bookmarkId}', [ApiV1\AudiobookController::class, 'destroyBookmark'])->whereNumber(['id', 'bookmarkId'])->name('audiobooks.bookmarks.destroy');
        Route::post('/audiobooks/{id}/match', [ApiV1\AudiobookController::class, 'match'])->whereNumber('id')->name('audiobooks.match');

        // Readers upload audiobooks, chunked and resumable (App\Audiobooks\AudiobookUploads):
        // create (limits checked) → PUT each chunk (idempotent) → complete → the owner decides.
        Route::post('/audiobook-uploads', [ApiV1\AudiobookUploadController::class, 'store'])->middleware('throttle:novarr-audiobook-upload')->name('audiobook_uploads.store');
        Route::get('/audiobook-uploads/{id}', [ApiV1\AudiobookUploadController::class, 'show'])->whereNumber('id')->name('audiobook_uploads.show');
        Route::put('/audiobook-uploads/{id}/files/{index}/chunks/{n}', [ApiV1\AudiobookUploadController::class, 'chunk'])->whereNumber(['id', 'index', 'n'])->name('audiobook_uploads.chunk');
        Route::post('/audiobook-uploads/{id}/complete', [ApiV1\AudiobookUploadController::class, 'complete'])->whereNumber('id')->name('audiobook_uploads.complete');
        Route::delete('/audiobook-uploads/{id}', [ApiV1\AudiobookUploadController::class, 'destroy'])->whereNumber('id')->name('audiobook_uploads.destroy');

        // The KOReader plugin's "update over Wi-Fi".
        Route::get('/koreader/plugin', [ApiV1\KoreaderPluginController::class, 'manifest'])->name('koreader.plugin');
        Route::get('/koreader/plugin/files/{path}', [ApiV1\KoreaderPluginController::class, 'file'])->where('path', '.*')->name('koreader.plugin.file');
    });
