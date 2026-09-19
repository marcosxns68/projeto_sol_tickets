<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\DailyAssignedTicketsSummary;
use App\Services\MailSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDailyAssigneeSummary extends Command
{
    protected $signature = 'tickets:daily-assignee-summary';
    protected $description = 'Envia a cada usuário um resumo dos tickets abertos atribuídos a ele';

    public function handle(MailSettings $mailSettings): int
    {
        // Os comandos CLI não passam pelo middleware HTTP que aplica o SMTP salvo no painel.
        $mailSettings->apply();

        $date = now('America/Sao_Paulo')->toDateString();
        $formattedDate = now('America/Sao_Paulo')->format('d/m/Y');
        $failed = 0;
        $sent = 0;

        User::query()
            ->where('active', true)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($date, $formattedDate, &$failed, &$sent): void {
                foreach ($users as $user) {
                    $key = 'daily_assignee_summary.last_sent.'.$user->id;

                    if (Setting::getValue($key) === $date) {
                        continue;
                    }

                    $query = Ticket::query()
                        ->where('assignee_id', $user->id)
                        ->activeForBox();

                    $total = (clone $query)->count();
                    $tickets = (clone $query)
                        ->orderByRaw('due_at IS NULL')
                        ->orderBy('due_at')
                        ->orderBy('id')
                        ->limit(10)
                        ->get(['number', 'title', 'due_at'])
                        ->map(fn (Ticket $ticket) => [
                            'number' => $ticket->number,
                            'title' => $ticket->title,
                            'due_at' => $ticket->due_at?->format('d/m/Y H:i'),
                        ])
                        ->all();

                    try {
                        $user->notify(new DailyAssignedTicketsSummary($total, $tickets, $formattedDate));

                        // Marca somente depois de enviar: uma falha de SMTP permite tentar novamente.
                        Setting::setValue($key, $date);
                        $sent++;
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('Falha no resumo diário dos tickets', [
                            'user_id' => $user->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Resumos enviados: {$sent}; falhas: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
