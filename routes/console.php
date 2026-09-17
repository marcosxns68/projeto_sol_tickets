<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tickets:maintenance')->hourly()->withoutOverlapping();
Schedule::command('tickets:process-recurrences')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --tries=5 --timeout=30')->everyMinute()->withoutOverlapping();
