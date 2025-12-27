<?php

use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;

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
Route::get('/ping', function () {
    return response('pong', 200);
});

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
