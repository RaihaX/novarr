<?php

use App\Http\Controllers\Voyager\CommandController;
use App\Http\Controllers\Voyager\LogController;

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
    Route::get('/', 'HomeController@index')->name('home');
    Route::get('/home', 'HomeController@index')->name('home');

    /** Datatables */
    Route::get('/languages/datatables', 'LanguageController@datatables')->name('languages.datatables');
    Route::get('/groups/datatables', 'GroupController@datatables')->name('groups.datatables');
    Route::get('/home/datatables/latest_chapters', 'HomeController@datatables_latest_chapters')->name('home.datatables_latest_chapters');
    Route::get('/home/datatables/missing_chapters', 'HomeController@datatables_missing_chapters')->name('home.datatables_missing_chapters');
    Route::get('/mangas/datatables', 'MangaController@datatables')->name('mangas.datatables');
    Route::get('/mangas/datatables/{id}', 'MangaChapterController@datatables')->name('mangachapters.datatables');
    Route::get('/novels/datatables', 'NovelController@datatables')->name('novels.datatables');
    Route::get('/novels/datatables/{id}', 'NovelChapterController@datatables')->name('novelchapters.datatables');

    /** Scrapper */
    Route::get('/novelchapters/scraper/{id}', 'NovelChapterController@novel_scraper')->name('novelchapters.scraper');
    Route::get('/novelchapters/chapter_scraper/{id}', 'NovelChapterController@chapter_scraper')->name('novelchapters.chapter_scraper');
    Route::get('/novelchapters/new_chapters_scraper/{id}', 'NovelChapterController@new_chapters_scraper')->name('novelchapters.new_chapters_scraper');

    /** Resources */
    Route::resources([
        'groups' => 'GroupController',
        'languages' => 'LanguageController',
        'mangas' => 'MangaController',
        'mangachapters' => 'MangaChapterController',
        'novels' => 'NovelController',
        'novelchapters' => 'NovelChapterController'
    ]);

    /** Custom Route */
    Route::get('/novelchapters/missing_chapters/{id}', 'NovelChapterController@missing_chapters')->name('novelchapters.missing_chapters');
    Route::get('/novelchapters/blacklist/{id}', 'NovelChapterController@blacklist')->name('novelchapters.blacklist');
    Route::get('/novelchapters/delete_all_chapters/{id}', 'NovelChapterController@delete_all_chapters')->name('novelchapters.delete_all_chapters');
    Route::get('/novelchapters/qidian_pirate/{id}', 'NovelChapterController@convertQidianToPirateSite')->name('novelchapters.qidian_pirate');
    Route::post('/novelchapters/generate_chapter_file', 'NovelChapterController@generate_chapter_file')->name('novelchapters.generate_chapter_file');

    Route::get('/mangas/getmanga/{id}', 'MangaController@get_manga')->name('mangas.get_manga');

    Route::get('/novels/download_epub/{id}', 'NovelController@download_epub')->name('novels.download_epub');
    Route::get('/novels/getnovel/{id}', 'NovelController@get_novel')->name('novels.get_novel');
    Route::get('/novels/getmetadata/{id}', 'NovelController@update_metadata')->name('novels.get_metadata');

    Route::post('/novels/search/novelupdates', 'NovelController@search_novels')->name('novels.search_novel_updates');

    Route::get('/rss_feed', 'GroupController@rss_feed')->name('groups.rss_feed');
});