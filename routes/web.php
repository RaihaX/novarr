<?php

use App\Http\Controllers\Voyager\CommandController;
use App\Http\Controllers\Voyager\LogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MangaController;
use App\Http\Controllers\MangaChapterController;
use App\Http\Controllers\NovelController;
use App\Http\Controllers\NovelChapterController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Auth::routes();

/*
|--------------------------------------------------------------------------
| Voyager Admin Routes
|--------------------------------------------------------------------------
*/
Route::group(['prefix' => 'admin'], function () {
    Voyager::routes();

    // Custom command routes within Voyager admin
    Route::group(['middleware' => ['web', 'admin.user']], function () {
        Route::get('commands', [CommandController::class, 'index'])->name('voyager.commands.index');
        Route::get('commands/{command}', [CommandController::class, 'showForm'])->name('voyager.commands.form');
        Route::post('commands/execute', [CommandController::class, 'execute'])->name('voyager.commands.execute');
        Route::post('commands/execute-async', [CommandController::class, 'executeAsync'])->name('voyager.commands.execute-async');
        Route::get('commands/status/{jobId}', [CommandController::class, 'status'])->name('voyager.commands.status');

        // Log viewer routes
        Route::get('logs', [LogController::class, 'index'])->name('voyager.logs.index');
        Route::get('logs/{filename}', [LogController::class, 'show'])->name('voyager.logs.show');
        Route::get('logs/{filename}/tail', [LogController::class, 'tail'])->name('voyager.logs.tail');
        Route::get('logs/{filename}/download', [LogController::class, 'download'])->name('voyager.logs.download');
        Route::delete('logs/{filename}', [LogController::class, 'destroy'])->name('voyager.logs.destroy');
    });
});

Route::group(['middleware' => ['auth']], function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/home', [HomeController::class, 'index'])->name('home');

    /** Datatables */
    Route::get('/languages/datatables', [LanguageController::class, 'datatables'])->name('languages.datatables');
    Route::get('/groups/datatables', [GroupController::class, 'datatables'])->name('groups.datatables');
    Route::get('/home/datatables/latest_chapters', [HomeController::class, 'datatables_latest_chapters'])->name('home.datatables_latest_chapters');
    Route::get('/home/datatables/missing_chapters', [HomeController::class, 'datatables_missing_chapters'])->name('home.datatables_missing_chapters');
    Route::get('/mangas/datatables', [MangaController::class, 'datatables'])->name('mangas.datatables');
    Route::get('/mangas/datatables/{id}', [MangaChapterController::class, 'datatables'])->name('mangachapters.datatables');
    Route::get('/novels/datatables', [NovelController::class, 'datatables'])->name('novels.datatables');
    Route::get('/novels/datatables/{id}', [NovelChapterController::class, 'datatables'])->name('novelchapters.datatables');

    /** Scrapper */
    Route::get('/novelchapters/scraper/{id}', [NovelChapterController::class, 'novel_scraper'])->name('novelchapters.scraper');
    Route::get('/novelchapters/chapter_scraper/{id}', [NovelChapterController::class, 'chapter_scraper'])->name('novelchapters.chapter_scraper');
    Route::get('/novelchapters/new_chapters_scraper/{id}', [NovelChapterController::class, 'new_chapters_scraper'])->name('novelchapters.new_chapters_scraper');

    /** Resources */
    Route::resources([
        'groups' => GroupController::class,
        'languages' => LanguageController::class,
        'mangas' => MangaController::class,
        'mangachapters' => MangaChapterController::class,
        'novels' => NovelController::class,
        'novelchapters' => NovelChapterController::class
    ]);

    /** Custom Route */
    Route::get('/novelchapters/missing_chapters/{id}', [NovelChapterController::class, 'missing_chapters'])->name('novelchapters.missing_chapters');
    Route::get('/novelchapters/blacklist/{id}', [NovelChapterController::class, 'blacklist'])->name('novelchapters.blacklist');
    Route::get('/novelchapters/delete_all_chapters/{id}', [NovelChapterController::class, 'delete_all_chapters'])->name('novelchapters.delete_all_chapters');
    Route::get('/novelchapters/qidian_pirate/{id}', [NovelChapterController::class, 'convertQidianToPirateSite'])->name('novelchapters.qidian_pirate');
    Route::post('/novelchapters/generate_chapter_file', [NovelChapterController::class, 'generate_chapter_file'])->name('novelchapters.generate_chapter_file');

    Route::get('/mangas/getmanga/{id}', [MangaController::class, 'get_manga'])->name('mangas.get_manga');

    Route::get('/novels/download_epub/{id}', [NovelController::class, 'download_epub'])->name('novels.download_epub');
    Route::get('/novels/getnovel/{id}', [NovelController::class, 'get_novel'])->name('novels.get_novel');
    Route::get('/novels/getmetadata/{id}', [NovelController::class, 'update_metadata'])->name('novels.get_metadata');

    Route::post('/novels/search/novelupdates', [NovelController::class, 'search_novels'])->name('novels.search_novel_updates');

    Route::get('/rss_feed', [GroupController::class, 'rss_feed'])->name('groups.rss_feed');
});