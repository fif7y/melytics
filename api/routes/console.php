<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('melytics:rollup')->everyMinute()->withoutOverlapping();
Schedule::command('melytics:prune')->dailyAt('04:00');
Schedule::command('melytics:digest')->mondays()->at('08:00');
