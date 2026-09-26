<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupReadNotifications extends Command
{
    protected $signature = 'tickets:cleanup-notifications';
    protected $description = 'Apaga notificações lidas há mais de sete dias e preserva as não lidas';

    public function handle(): int
    {
        $deleted = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(7))
            ->delete();

        $this->info($deleted.' notificação(ões) lida(s) removida(s).');

        return self::SUCCESS;
    }
}
