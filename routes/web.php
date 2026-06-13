<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\NovelController;
use App\Http\Controllers\NovelChapterController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\LogController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Dashboard
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::view('/offline', 'offline')->name('offline');

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
Route::post('/novels/{id}/toggle-complete', [NovelController::class, 'toggleComplete'])->name('novels.toggle_complete');
Route::post('/novels/{id}/tags', [NovelController::class, 'syncTags'])->name('novels.sync_tags');
Route::post('/tags', [NovelController::class, 'storeTag'])->name('tags.store');
Route::get('/novels/{id}/jump', [NovelController::class, 'jumpChapter'])->name('novels.jump_chapter');
Route::post('/novels/{id}/remove-duplicates', [NovelController::class, 'removeDuplicates'])->name('novels.remove_duplicates');
Route::delete('/novels/{id}', [NovelController::class, 'destroy'])->name('novels.destroy');
Route::get('/novels/{id}/epub', [NovelController::class, 'download_epub'])->name('novels.download_epub');
Route::get('/novels/{id}/metadata', [NovelController::class, 'update_metadata'])->name('novels.get_metadata');

// Chapters
Route::get('/chapters/{id}', [NovelChapterController::class, 'show'])->name('chapters.show');
Route::post('/chapters/{id}/toggle-read', [NovelChapterController::class, 'toggleRead'])->name('chapters.toggle_read');
Route::post('/chapters/{id}/read-through', [NovelChapterController::class, 'readThrough'])->name('chapters.read_through');
Route::post('/chapters/bulk-read', [NovelChapterController::class, 'bulkRead'])->name('chapters.bulk_read');

// Commands (status route BEFORE wildcard {command})
Route::get('/commands', [CommandController::class, 'index'])->name('commands.index');
Route::post('/commands/execute', [CommandController::class, 'execute'])->name('commands.execute');
Route::post('/commands/execute-async', [CommandController::class, 'executeAsync'])->name('commands.execute-async');
Route::get('/commands/status/{jobId}', [CommandController::class, 'status'])->name('commands.status');
Route::get('/commands/{command}', [CommandController::class, 'showForm'])->name('commands.form');

// Logs
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');

Route::get('/health', [SystemHealthController::class, 'index'])->name('health.index');
Route::get('/health/job/{uuid}', [SystemHealthController::class, 'failedJob'])->name('health.job');
Route::post('/health/retry/{uuid}', [SystemHealthController::class, 'retry'])->name('health.retry');
Route::post('/health/retry-all', [SystemHealthController::class, 'retryAll'])->name('health.retry_all');
Route::post('/health/forget/{uuid}', [SystemHealthController::class, 'forget'])->name('health.forget');
Route::post('/health/flush', [SystemHealthController::class, 'flush'])->name('health.flush');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/settings/test-email', [SettingsController::class, 'testEmail'])->name('settings.test_email');
Route::post('/settings/test-flaresolverr', [SettingsController::class, 'testFlareSolverr'])->name('settings.test_flaresolverr');
Route::post('/settings/test-notification', [SettingsController::class, 'testNotification'])->name('settings.test_notification');

Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
Route::get('/logs/{filename}/tail', [LogController::class, 'tail'])->name('logs.tail');
Route::post('/logs/{filename}/clear', [LogController::class, 'clear'])->name('logs.clear');
Route::get('/logs/{filename}/download', [LogController::class, 'download'])->name('logs.download');
Route::delete('/logs/{filename}', [LogController::class, 'destroy'])->name('logs.destroy');
Route::get('/logs/{filename}', [LogController::class, 'show'])->name('logs.show');
