<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\WhatsAppConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTicketOpenedWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Uma tentativa evita mensagens duplicadas se a API receber o envio
    // e a conexão cair antes de devolver a confirmação ao servidor.
    public int $tries = 1;
    public int $timeout = 25;

    public function __construct(public int $ticketId) {}

    public function handle(WhatsAppConnection $connection): void
    {
        Cache::lock('tickets.whatsapp.opened.'.$this->ticketId, 40)->block(5, function () use ($connection) {
            $ticket = Ticket::find($this->ticketId);
            if (!$ticket || $ticket->whatsapp_opened_sent_at || !$ticket->requester_whatsapp) {
                return;
            }

            if (!$connection->values()['api_key_saved']) {
                Log::info('Confirmação WhatsApp não enviada: instância não configurada.', [
                    'ticket_id' => $ticket->id,
                ]);
                return;
            }

            try {
                $connection->sendText(
                    $ticket->requester_whatsapp,
                    "Sutoorii Tickets\n\nSeu ticket de número {$ticket->number} foi aberto com sucesso."
                );
                $ticket->update(['whatsapp_opened_sent_at' => now()]);
            } catch (Throwable $exception) {
                Log::warning('Falha ao enviar confirmação de abertura por WhatsApp.', [
                    'ticket_id' => $ticket->id,
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}
