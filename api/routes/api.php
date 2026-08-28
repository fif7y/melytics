<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

// Ingest — neutral naming on purpose (ad-blocker lists match on keywords).
Route::post('/echo', IngestController::class)->middleware('throttle:ingest');
Route::get('/echo.gif', [IngestController::class, 'pixel'])->middleware('throttle:ingest');

// What the login screen may offer on this instance (unauthenticated by design).
Route::get('/auth/config', fn () => [
    'registration' => (bool) config('melytics.registration'),
    'google' => GoogleAuthController::enabled(),
]);

// MCP for AI assistants — token in the Authorization header, or in the path
// for clients that can't send headers (claude.ai custom connectors).
Route::post('/mcp/{token?}', [McpController::class, 'handle'])->middleware('throttle:120,1');
Route::match(['get', 'delete'], '/mcp/{token?}', [McpController::class, 'reject']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:10,1');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/forgot', [AuthController::class, 'forgot'])->middleware('throttle:login');
Route::post('/auth/reset', [AuthController::class, 'reset'])->middleware('throttle:5,1');
Route::get('/auth/verify/{id}/{hash}', [AuthController::class, 'verify'])
    ->middleware(['signed', 'throttle:10,1'])->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/resend-verification', [AuthController::class, 'resendVerification'])->middleware('throttle:5,1');
    Route::post('/auth/mcp-token', [AuthController::class, 'mcpToken'])->middleware('throttle:5,1');
    Route::put('/auth/google/settings', [GoogleAuthController::class, 'saveSettings']);
    Route::delete('/auth/google/settings', [GoogleAuthController::class, 'removeSettings']);

    Route::post('/update/check', [UpdateController::class, 'check'])->middleware('throttle:10,1');
    Route::post('/update/run', [UpdateController::class, 'run'])->middleware('throttle:3,10');

    Route::apiResource('sites', SiteController::class)->except('show');

    Route::get('/sites/{site}/stats', [StatsController::class, 'stats']);
    Route::get('/sites/{site}/dashboard', [StatsController::class, 'dashboard']);
    Route::get('/sites/{site}/breakdown', [StatsController::class, 'breakdown']);
    Route::get('/sites/{site}/live', [StatsController::class, 'live']);
    Route::get('/sites/{site}/targets', [StatsController::class, 'targets']);
    Route::get('/sites/{site}/goals', [StatsController::class, 'goals']);
    Route::get('/sites/{site}/vitals', [StatsController::class, 'vitals']);
    Route::get('/sites/{site}/retention', [StatsController::class, 'retention']);
    Route::get('/sites/{site}/cohorts', [StatsController::class, 'cohorts']);
    Route::get('/sites/{site}/loyalty', [StatsController::class, 'loyalty']);
    Route::get('/sites/{site}/attribution', [StatsController::class, 'attribution']);
    Route::get('/sites/{site}/time-to-convert', [StatsController::class, 'timeToConvert']);

    Route::post('/sites/{site}/goals', [GoalController::class, 'store']);
    Route::patch('/sites/{site}/goals/{goal}', [GoalController::class, 'update']);
    Route::delete('/sites/{site}/goals/{goal}', [GoalController::class, 'destroy']);

    Route::get('/sites/{site}/funnels', [FunnelController::class, 'index']);
    Route::post('/sites/{site}/funnels', [FunnelController::class, 'store']);
    Route::patch('/sites/{site}/funnels/{funnel}', [FunnelController::class, 'update']);
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
