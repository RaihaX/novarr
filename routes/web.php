<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\NovelController;
use App\Http\Controllers\NovelChapterController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TailscaleController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\LogController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Who may open each route is App\Support\RouteAccess (public / reader /
| admin, default admin), enforced by App\Http\Middleware\IdentifyReader on
| the whole web group when "Require sign-in" is on. A new route must be
| listed there (tests/Feature/RouteAccessTest fails otherwise).
|
*/

// Read-only JSON/XML endpoints the PWA and ereaders poll: an ETag of the body
// lets a repeat fetch come back 304. Never on chapters.show — a GET there
// marks the chapter read, so it must always reach the controller.
$conditionalGet = 'cache.headers:private;max_age=0;etag';

// Sign in / out (App\Http\Controllers\Auth\LoginController)
Route::get('/login', [\App\Http\Controllers\Auth\LoginController::class, 'show'])->name('login');
Route::post('/login', [\App\Http\Controllers\Auth\LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [\App\Http\Controllers\Auth\LoginController::class, 'destroy'])->name('logout');
// The second step after the password on the public address, and "Sign in
// with a passkey" (public, throttled per address).
Route::middleware('throttle:novarr-second-step')->group(function () {
    Route::get('/login/second-step', [\App\Http\Controllers\Auth\SecondStepController::class, 'show'])->name('login.second_step');
    Route::post('/login/second-step', [\App\Http\Controllers\Auth\SecondStepController::class, 'verify'])->name('login.second_step.verify');
    Route::post('/login/second-step/email', [\App\Http\Controllers\Auth\SecondStepController::class, 'email'])->name('login.second_step.email');
    Route::post('/login/second-step/passkey', [\App\Http\Controllers\Auth\SecondStepController::class, 'passkeyOptions'])->name('login.second_step.passkey_options');
    Route::post('/login/passkey/options', [\App\Http\Controllers\Auth\PasskeyLoginController::class, 'options'])->name('login.passkey.options');
    Route::post('/login/passkey', [\App\Http\Controllers\Auth\PasskeyLoginController::class, 'verify'])->name('login.passkey.verify');
});

// Accepting an invite (public; App\Http\Controllers\Auth\InviteController)
Route::get('/invite/{token}', [\App\Http\Controllers\Auth\InviteController::class, 'show'])->middleware('throttle:novarr-invite')->name('invite.show');
Route::post('/invite/{token}', [\App\Http\Controllers\Auth\InviteController::class, 'accept'])->middleware('throttle:novarr-invite')->name('invite.accept');

// "Stop these emails" from a reader's daily summary (public; the signed link
// is the credential; App\Http\Controllers\SummaryUnsubscribeController).
Route::get('/summary/stop/{id}', [\App\Http\Controllers\SummaryUnsubscribeController::class, 'show'])->whereNumber('id')->middleware('throttle:novarr-unsubscribe')->name('summary.unsubscribe');
Route::post('/summary/stop/{id}', [\App\Http\Controllers\SummaryUnsubscribeController::class, 'stop'])->whereNumber('id')->middleware('throttle:novarr-unsubscribe')->name('summary.unsubscribe.stop');

// The Android app's APK (public download; App\Support\AndroidApp)
Route::get('/app/android', [\App\Http\Controllers\AndroidAppController::class, 'download'])->middleware('throttle:novarr-download')->name('app.android');

// Your account: name, password, signed-in devices, linking a device by code
Route::get('/account', [\App\Http\Controllers\AccountController::class, 'show'])->name('account');
Route::post('/account/profile', [\App\Http\Controllers\AccountController::class, 'updateProfile'])->name('account.profile');
Route::post('/account/password', [\App\Http\Controllers\AccountController::class, 'updatePassword'])->name('account.password');
Route::delete('/account/tokens/{id}', [\App\Http\Controllers\AccountController::class, 'revokeToken'])->whereNumber('id')->name('account.tokens.revoke');
Route::post('/account/link', [\App\Http\Controllers\AccountController::class, 'linkLookup'])->name('account.link');
Route::post('/account/link/confirm', [\App\Http\Controllers\AccountController::class, 'linkConfirm'])->name('account.link.confirm');
Route::post('/account/browsers/sign-out-others', [\App\Http\Controllers\AccountController::class, 'signOutOtherBrowsers'])->name('account.browsers.sign_out_others');
Route::delete('/account/browsers/{id}', [\App\Http\Controllers\AccountController::class, 'signOutBrowser'])->whereNumber('id')->name('account.browsers.sign_out');
Route::post('/account/send-to', [\App\Http\Controllers\AccountController::class, 'sendTo'])->name('account.send_to');
Route::post('/account/summary-email', [\App\Http\Controllers\AccountController::class, 'summaryEmail'])->name('account.summary_email');
// Sign-in steps and catalogue passwords (App\Http\Controllers\AccountSecurityController);
// changes need a recent "Confirm it's you" (App\Support\RecentConfirmation).
Route::controller(\App\Http\Controllers\AccountSecurityController::class)->group(function () {
    Route::post('/account/confirm', 'confirm')->middleware('throttle:novarr-second-step')->name('account.confirm');
    Route::post('/account/confirm/email', 'confirmEmail')->middleware('throttle:novarr-second-step')->name('account.confirm.email');
    Route::post('/account/confirm/passkey', 'confirmPasskeyOptions')->middleware('throttle:novarr-second-step')->name('account.confirm.passkey_options');
    Route::post('/account/passkeys/options', 'passkeyOptions')->name('account.passkeys.options');
    Route::post('/account/passkeys', 'storePasskey')->name('account.passkeys.store');
    Route::delete('/account/passkeys/{id}', 'destroyPasskey')->whereNumber('id')->name('account.passkeys.destroy');
    Route::post('/account/authenticator/start', 'startAuthenticator')->name('account.authenticator.start');
    Route::post('/account/authenticator', 'storeAuthenticator')->name('account.authenticator.store');
    Route::delete('/account/authenticator', 'destroyAuthenticator')->name('account.authenticator.destroy');
    Route::post('/account/recovery-codes', 'recoveryCodes')->name('account.recovery_codes');
    Route::post('/account/email-codes', 'emailCodes')->name('account.email_codes');
    Route::post('/account/catalogue-passwords', 'storeCataloguePassword')->name('account.catalogue.store');
    Route::delete('/account/catalogue-passwords/{id}', 'destroyCataloguePassword')->whereNumber('id')->name('account.catalogue.destroy');
});

// Dashboard
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/continue', [HomeController::class, 'continue'])->name('continue');
Route::get('/activity', [\App\Http\Controllers\ActivityController::class, 'index'])->name('activity.index');
Route::get('/activity/downloads', [\App\Http\Controllers\ActivityController::class, 'downloads'])->name('activity.downloads');
// Readers: what every account reads (admin; App\Services\ReaderActivity)
Route::get('/readers', [\App\Http\Controllers\ReaderActivityController::class, 'index'])->name('readers.index');
Route::get('/readers/now', [\App\Http\Controllers\ReaderActivityController::class, 'now'])->name('readers.now');
Route::get('/readers/history', [\App\Http\Controllers\ReaderActivityController::class, 'history'])->name('readers.history');
Route::get('/readers/{id}', [\App\Http\Controllers\ReaderActivityController::class, 'show'])->whereNumber('id')->name('readers.show');
Route::view('/offline', 'offline')->name('offline');
Route::view('/library', 'library')->name('library');

// Novels
Route::get('/novels', [NovelController::class, 'index'])->name('novels.index');
Route::get('/novels/create', [NovelController::class, 'create'])->name('novels.create');
Route::get('/novels/discover', [DiscoverController::class, 'index'])->name('novels.discover');
Route::get('/novels/discover/browse', [DiscoverController::class, 'browse'])->name('novels.discover.browse');
Route::post('/novels', [NovelController::class, 'store'])->name('novels.store');
Route::post('/novels/bulk', [NovelController::class, 'bulk'])->name('novels.bulk');
Route::get('/novels/{id}', [NovelController::class, 'show'])->name('novels.show');
Route::get('/novels/{id}/edit', [NovelController::class, 'edit'])->name('novels.edit');
Route::put('/novels/{id}', [NovelController::class, 'update'])->name('novels.update');
Route::post('/novels/{id}/toggle-pause', [NovelController::class, 'togglePause'])->name('novels.toggle_pause');
Route::post('/novels/{id}/toggle-frequent', [NovelController::class, 'toggleFrequent'])->name('novels.toggle_frequent');
Route::post('/novels/{id}/toggle-complete', [NovelController::class, 'toggleComplete'])->name('novels.toggle_complete');
Route::post('/novels/{id}/attention/snooze', [\App\Http\Controllers\AttentionController::class, 'snooze'])->name('novels.attention_snooze');
Route::post('/novels/{id}/tags', [NovelController::class, 'syncTags'])->name('novels.sync_tags');
Route::post('/tags', [NovelController::class, 'storeTag'])->name('tags.store');
Route::get('/novels/{id}/jump', [NovelController::class, 'jumpChapter'])->name('novels.jump_chapter');
Route::post('/novels/{id}/remove-duplicates', [NovelController::class, 'removeDuplicates'])->name('novels.remove_duplicates');
Route::delete('/novels/{id}', [NovelController::class, 'destroy'])->name('novels.destroy');
Route::get('/novels/{id}/epub', [NovelController::class, 'download_epub'])->name('novels.download_epub');
Route::get('/novels/{id}/metadata/candidates', [\App\Http\Controllers\MetadataController::class, 'candidates'])->name('novels.metadata_candidates');
Route::post('/novels/{id}/metadata/choose', [\App\Http\Controllers\MetadataController::class, 'choose'])->name('novels.metadata_choose');
Route::get('/novels/{id}/offline-manifest', [NovelController::class, 'offlineManifest'])->middleware($conditionalGet)->name('novels.offline_manifest');
Route::get('/novels/{id}/chapters-json', [NovelController::class, 'chaptersJson'])->middleware($conditionalGet)->name('novels.chapters_json');
Route::get('/novels/{id}/snapshots/{file?}', [\App\Http\Controllers\SnapshotController::class, 'show'])->where('file', '[0-9]+-[a-z_]+-[0-9_]+\.html\.gz')->name('novels.snapshots');

// Chapters
Route::get('/chapters/{id}', [NovelChapterController::class, 'show'])->name('chapters.show');
Route::post('/chapters/{id}/toggle-read', [NovelChapterController::class, 'toggleRead'])->name('chapters.toggle_read');
Route::post('/chapters/{id}/read-through', [NovelChapterController::class, 'readThrough'])->name('chapters.read_through');
Route::post('/chapters/{id}/progress', [NovelChapterController::class, 'progress'])->name('chapters.progress');
Route::post('/chapters/bulk-read', [NovelChapterController::class, 'bulkRead'])->name('chapters.bulk_read');

// Manga reader (App\Http\Controllers\MangaController). Page images skip the
// session/cookie middleware: they are static, cacheable and fetched by the
// dozen per chapter.
$sessionless = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
];
Route::get('/chapters/{id}/pages.json', [\App\Http\Controllers\MangaController::class, 'pages'])->whereNumber('id')->name('chapters.pages');
Route::get('/chapters/{id}/pages/{page}', [\App\Http\Controllers\MangaController::class, 'page'])
    ->whereNumber(['id', 'page'])
    ->withoutMiddleware($sessionless)
    ->name('chapters.page');
Route::post('/novels/{id}/reading-mode', [\App\Http\Controllers\MangaController::class, 'readingMode'])->whereNumber('id')->name('novels.reading_mode');

// Discover covers from hosts that refuse hotlinking (MangaDex).
Route::get('/discover/cover', [\App\Http\Controllers\DiscoverCoverController::class, 'show'])
    ->withoutMiddleware($sessionless)
    ->name('discover.cover');

// Reader requests: the page (its data comes from /api/v1/requests…).
Route::get('/requests', [\App\Http\Controllers\RequestsPageController::class, 'index'])->name('requests.index');

// Books: any reader uploads an ePub; the owner matches and approves it (App\Books\BookUploads).
Route::get('/books/upload', [\App\Http\Controllers\BookUploadController::class, 'create'])->name('books.upload');
Route::post('/books/upload', [\App\Http\Controllers\BookUploadController::class, 'store'])->middleware('throttle:novarr-book-upload')->name('books.upload.store');
Route::get('/books/calibre', [\App\Http\Controllers\CalibreController::class, 'index'])->name('books.calibre');
Route::post('/books/calibre/import', [\App\Http\Controllers\CalibreController::class, 'import'])->name('books.calibre.import');
Route::get('/books/calibre/cover/{id}', [\App\Http\Controllers\CalibreController::class, 'cover'])->whereNumber('id')->name('books.calibre.cover');
Route::get('/books/review/{id}', [\App\Http\Controllers\BookReviewController::class, 'show'])->whereNumber('id')->name('books.review');
Route::post('/books/review/{id}/search', [\App\Http\Controllers\BookReviewController::class, 'search'])->whereNumber('id')->name('books.review.search');
Route::post('/books/review/{id}/approve', [\App\Http\Controllers\BookReviewController::class, 'approve'])->whereNumber('id')->name('books.review.approve');
Route::post('/books/review/{id}/decline', [\App\Http\Controllers\BookReviewController::class, 'decline'])->whereNumber('id')->name('books.review.decline');

// Covers in reader-request results: signed URLs only (RequestCoverController).
Route::get('/requests/cover', [\App\Http\Controllers\RequestCoverController::class, 'cover'])
    ->withoutMiddleware($sessionless)
    ->name('requests.cover');

// Commands (status route BEFORE wildcard {command})
Route::get('/commands', [CommandController::class, 'index'])->name('commands.index');
Route::post('/commands/execute', [CommandController::class, 'execute'])->name('commands.execute');
Route::post('/commands/execute-async', [CommandController::class, 'executeAsync'])->name('commands.execute-async');
Route::get('/commands/status/{jobId}', [CommandController::class, 'status'])->name('commands.status');
Route::get('/commands/{command}', [CommandController::class, 'showForm'])->name('commands.form');

// Search
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/palette', [SearchController::class, 'palette'])->middleware($conditionalGet)->name('palette');

// Reading stats + bookmarks
Route::get('/stats', [\App\Http\Controllers\StatsController::class, 'index'])->name('stats.index');
Route::get('/bookmarks', [\App\Http\Controllers\BookmarkController::class, 'index'])->name('bookmarks.index');
Route::post('/bookmarks', [\App\Http\Controllers\BookmarkController::class, 'store'])->name('bookmarks.store');
Route::delete('/bookmarks/{id}', [\App\Http\Controllers\BookmarkController::class, 'destroy'])->name('bookmarks.destroy');

// OPDS catalog for ereader apps
Route::get('/opds', [\App\Http\Controllers\OpdsController::class, 'index'])->middleware($conditionalGet)->name('opds.index');

Route::get('/health', [SystemHealthController::class, 'index'])->name('health.index');
Route::get('/health/job/{uuid}', [SystemHealthController::class, 'failedJob'])->name('health.job');
Route::post('/health/retry/{uuid}', [SystemHealthController::class, 'retry'])->name('health.retry');
Route::post('/health/retry-all', [SystemHealthController::class, 'retryAll'])->name('health.retry_all');
Route::post('/health/forget/{uuid}', [SystemHealthController::class, 'forget'])->name('health.forget');
Route::post('/health/flush', [SystemHealthController::class, 'flush'])->name('health.flush');
Route::get('/health/sources', [\App\Http\Controllers\SourceHealthController::class, 'index'])->name('health.sources');
Route::get('/health/storage', [\App\Http\Controllers\StorageUsageController::class, 'index'])->name('health.storage');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/settings/test-email', [SettingsController::class, 'testEmail'])->name('settings.test_email');
Route::post('/settings/test-flaresolverr', [SettingsController::class, 'testFlareSolverr'])->name('settings.test_flaresolverr');
Route::post('/settings/test-notification', [SettingsController::class, 'testNotification'])->name('settings.test_notification');

// Settings → Users: accounts and the "Require sign-in" switch (admin; also
// while sign-in is off, so accounts can be prepared).
Route::get('/settings/users', [\App\Http\Controllers\UserController::class, 'index'])->name('settings.users');
Route::post('/settings/users', [\App\Http\Controllers\UserController::class, 'store'])->name('settings.users.store');
Route::put('/settings/users/{id}', [\App\Http\Controllers\UserController::class, 'update'])->whereNumber('id')->name('settings.users.update');
Route::post('/settings/users/{id}/password', [\App\Http\Controllers\UserController::class, 'password'])->whereNumber('id')->name('settings.users.password');
Route::post('/settings/users/{id}/disable', [\App\Http\Controllers\UserController::class, 'toggleDisabled'])->whereNumber('id')->name('settings.users.disable');
Route::delete('/settings/users/{id}', [\App\Http\Controllers\UserController::class, 'destroy'])->whereNumber('id')->name('settings.users.destroy');
Route::post('/settings/users/{id}/second-step/reset', [\App\Http\Controllers\UserController::class, 'resetSecondStep'])->whereNumber('id')->name('settings.users.second_step.reset');
Route::post('/settings/users/invites', [\App\Http\Controllers\UserInviteController::class, 'store'])->name('settings.users.invites.store');
Route::post('/settings/users/invites/{id}/resend', [\App\Http\Controllers\UserInviteController::class, 'resend'])->whereNumber('id')->name('settings.users.invites.resend');
Route::delete('/settings/users/invites/{id}', [\App\Http\Controllers\UserInviteController::class, 'destroy'])->whereNumber('id')->name('settings.users.invites.destroy');
Route::post('/settings/sign-in', [\App\Http\Controllers\UserController::class, 'auth'])->name('settings.users.auth');
// Settings → Users → Sign-in history (admin; App\AuthEvent)
Route::get('/settings/users/history', [\App\Http\Controllers\AuthHistoryController::class, 'index'])->name('settings.users.history');

// Tailscale panel (status + Serve/Funnel toggles)
Route::get('/settings/tailscale', [TailscaleController::class, 'status'])->name('settings.tailscale_status');
Route::post('/settings/tailscale/serve', [TailscaleController::class, 'serve'])->name('settings.tailscale_serve');
Route::post('/settings/tailscale/funnel', [TailscaleController::class, 'funnel'])->name('settings.tailscale_funnel');

Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
Route::get('/logs/{filename}/tail', [LogController::class, 'tail'])->name('logs.tail');
Route::post('/logs/{filename}/clear', [LogController::class, 'clear'])->name('logs.clear');
Route::get('/logs/{filename}/download', [LogController::class, 'download'])->name('logs.download');
Route::delete('/logs/{filename}', [LogController::class, 'destroy'])->name('logs.destroy');
Route::get('/logs/{filename}', [LogController::class, 'show'])->name('logs.show');
