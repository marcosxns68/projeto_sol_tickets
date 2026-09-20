<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tickets:maintenance')->hourly()->withoutOverlapping();
Schedule::command('tickets:process-recurrences')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --tries=5 --timeout=30')->everyMinute()->withoutOverlapping();
Schedule::command('tickets:daily-assignee-summary')->weeklyOn(1, '08:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
// Corrige gradualmente tickets antigos sem um número, sem disparar mensagens retroativas.
Schedule::command('tickets:backfill-requester-whatsapp --limit=5')->hourlyAt(25)->withoutOverlapping();
