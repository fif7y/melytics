<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

// Ingest — neutral naming on purpose (ad-blocker lists match on keywords).
Route::post('/echo', IngestController::class);
Route::get('/echo.gif', [IngestController::class, 'pixel']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('sites', SiteController::class)->except('show');

    Route::get('/sites/{site}/stats', [StatsController::class, 'stats']);
    Route::get('/sites/{site}/breakdown', [StatsController::class, 'breakdown']);
    Route::get('/sites/{site}/live', [StatsController::class, 'live']);
});
