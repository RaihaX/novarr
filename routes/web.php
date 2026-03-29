<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\NovelController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\LogController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Auth::routes();

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/
Route::group(['middleware' => ['auth']], function () {
    // Dashboard
    Route::get('/', [HomeController::class, 'index'])->name('home');

    // Novels
    Route::get('/novels', [NovelController::class, 'index'])->name('novels.index');
    Route::get('/novels/{id}', [NovelController::class, 'show'])->name('novels.show');
    Route::get('/novels/{id}/epub', [NovelController::class, 'download_epub'])->name('novels.download_epub');
    Route::get('/novels/{id}/metadata', [NovelController::class, 'update_metadata'])->name('novels.get_metadata');

    // Commands (status route BEFORE wildcard {command})
    Route::get('/commands', [CommandController::class, 'index'])->name('commands.index');
    Route::post('/commands/execute', [CommandController::class, 'execute'])->name('commands.execute');
    Route::post('/commands/execute-async', [CommandController::class, 'executeAsync'])->name('commands.execute-async');
    Route::get('/commands/status/{jobId}', [CommandController::class, 'status'])->name('commands.status');
    Route::get('/commands/{command}', [CommandController::class, 'showForm'])->name('commands.form');

    // Logs
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/{filename}/tail', [LogController::class, 'tail'])->name('logs.tail');
    Route::get('/logs/{filename}/download', [LogController::class, 'download'])->name('logs.download');
    Route::delete('/logs/{filename}', [LogController::class, 'destroy'])->name('logs.destroy');
    Route::get('/logs/{filename}', [LogController::class, 'show'])->name('logs.show');
});
