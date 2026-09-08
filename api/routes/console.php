<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('melytics:rollup')->everyMinute()->withoutOverlapping();
Schedule::command('melytics:prune')->dailyAt('04:00');
Schedule::command('melytics:digest')->mondays()->at('08:00');
Schedule::command('melytics:alerts')->hourly();
// Keep the release check warm so the update banner lands within the hour
// of a release, not whenever the 1h request-side cache happens to expire.
Schedule::call(fn () => \App\Support\Version::latest(fresh: true))->hourly()->name('melytics:release-check');
