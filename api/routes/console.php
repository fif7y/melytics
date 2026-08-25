<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('melytics:rollup')->everyMinute()->withoutOverlapping();
Schedule::command('melytics:prune')->dailyAt('04:00');
