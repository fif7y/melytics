<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('melytics:rollup')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('melytics:prune')->dailyAt('04:00');
