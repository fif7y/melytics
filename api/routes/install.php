<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// Loaded with NO middleware (bootstrap/app.php `then:`) — these routes must
// run before an APP_KEY or database exists, so no session/cookies/CSRF.

Route::get('/', function () {
    if (! InstallController::installed()) {
        return redirect('/install');
    }
    if (file_exists(public_path('app/index.html'))) {
        return redirect('/app/');
    }

    return view('welcome');
});

Route::get('/install', [InstallController::class, 'show']);
Route::post('/install', [InstallController::class, 'perform']);
