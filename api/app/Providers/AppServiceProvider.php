<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Public write endpoint: cap per IP so a leaked site key can't flood
        // the hits/bot_hits tables on shared hosting. Generous — real pages
        // send a pageview + occasional pings/events, nowhere near this.
        RateLimiter::for('ingest', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Credential endpoints: throttle by email AND ip, so a rotating-IP
        // attacker still hits a per-account ceiling (ip-only lets them spread).
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
        ]);
    }
}
