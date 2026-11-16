<?php

use Illuminate\Support\Facades\Schedule;

// CronJob schedule:run a cada minuto (spec §8.4).
Schedule::command('saga:check-timeouts')->everyMinute()->withoutOverlapping();
Schedule::command('videos:expire-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('messaging:prune')->dailyAt('03:00')->withoutOverlapping();
