<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tickets:mark-overdue')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tickets:notify-deadlines')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('tickets:generate-recurring')->everyMinute()->withoutOverlapping();
Schedule::command('attachments:expire')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --tries=5 --timeout=30')->everyMinute()->withoutOverlapping();
