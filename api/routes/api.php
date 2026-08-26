<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\ShareLinkController;
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
    Route::get('/sites/{site}/goals', [StatsController::class, 'goals']);
    Route::get('/sites/{site}/vitals', [StatsController::class, 'vitals']);

    Route::post('/sites/{site}/goals', [GoalController::class, 'store']);
    Route::delete('/sites/{site}/goals/{goal}', [GoalController::class, 'destroy']);

    Route::get('/sites/{site}/funnels', [FunnelController::class, 'index']);
    Route::post('/sites/{site}/funnels', [FunnelController::class, 'store']);
    Route::delete('/sites/{site}/funnels/{funnel}', [FunnelController::class, 'destroy']);

    Route::get('/sites/{site}/annotations', [AnnotationController::class, 'index']);
    Route::post('/sites/{site}/annotations', [AnnotationController::class, 'store']);
    Route::delete('/sites/{site}/annotations/{annotation}', [AnnotationController::class, 'destroy']);

    Route::get('/sites/{site}/share', [ShareLinkController::class, 'show']);
    Route::patch('/sites/{site}/share', [ShareLinkController::class, 'update']);
});

// Public share links (read-only, throttled; password enforced per link)
Route::middleware('throttle:60,1')->prefix('share/{token}')->group(function () {
    Route::get('/', [PublicShareController::class, 'meta']);
    Route::post('/unlock', [PublicShareController::class, 'unlock'])->middleware('throttle:10,1');
    Route::get('/stats', [PublicShareController::class, 'stats']);
    Route::get('/breakdown', [PublicShareController::class, 'breakdown']);
});
