<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\TicketWhatsAppAutomations;
use App\Services\WhatsAppConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTicketWhatsAppAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Evita repetir a mensagem caso a Evolution receba o envio e não responda.
    public int $tries = 1;
    public int $timeout = 25;

    public function __construct(public int $ticketId, public string $event) {}

    public function handle(WhatsAppConnection $connection): void
    {
        if (!in_array($this->event, ['opened', 'closed'], true)) {
            return;
        }

        $event = $this->event;
        Cache::lock('tickets.whatsapp.'.$event.'.'.$this->ticketId, 40)->block(5, function () use ($connection, $event) {
            $ticket = Ticket::find($this->ticketId);
            $column = $event === 'opened' ? 'whatsapp_opened_sent_at' : 'whatsapp_closed_sent_at';

            if (!$ticket || $ticket->{$column} || !$ticket->requester_whatsapp) {
                return;
            }

            // Se um ticket reabrir antes de o worker processar a fila,
            // uma confirmação de fechamento não pode ser enviada.
            if ($event === 'closed' && $ticket->status?->system_key !== 'closed') {
                return;
            }

            $automations = app(TicketWhatsAppAutomations::class);
            if (!$automations->enabled($event)) {
                return;
            }

            if (!$connection->values()['api_key_saved']) {
                Log::info('Notificação WhatsApp não enviada: instância não configurada.', [
                    'ticket_id' => $ticket->id,
                    'event' => $event,
                ]);
                return;
            }

            try {
                $connection->sendText(
                    $ticket->requester_whatsapp,
                    $automations->render($event, $ticket->number),
                );
                $ticket->update([$column => now()]);
            } catch (Throwable $exception) {
                Log::warning('Falha no envio da notificação de ticket por WhatsApp.', [
                    'ticket_id' => $ticket->id,
                    'event' => $event,
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}
