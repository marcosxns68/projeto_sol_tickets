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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTicketWhatsAppAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Não repetir uma mensagem em caso de resposta ambígua da Evolution API.
    public int $tries = 1;
    public int $timeout = 25;

    public function __construct(
        public int $ticketId,
        public string $event,
        public ?int $sourceId = null,
        public ?string $statusName = null,
    ) {}

    public function handle(WhatsAppConnection $connection): void
    {
        if (!array_key_exists($this->event, TicketWhatsAppAutomations::LABELS)) {
            return;
        }

        $event = $this->event;
        $key = $this->sourceId ?? $this->ticketId;
        Cache::lock('tickets.whatsapp.'.$event.'.'.$key, 40)->block(5, function () use ($connection, $event, $key) {
            $ticket = Ticket::find($this->ticketId);
            if (!$ticket || !$ticket->requester_whatsapp) {
                return;
            }

            $column = match ($event) {
                'opened' => 'whatsapp_opened_sent_at',
                'closed' => 'whatsapp_closed_sent_at',
                default => null,
            };

            if ($column && $ticket->{$column}) {
                return;
            }

            if ($event === 'closed' && $ticket->status?->system_key !== 'closed') {
                return;
            }

            if ($event === 'status' && ($ticket->status?->system_key === 'closed'
                || $ticket->status?->name !== $this->statusName)) {
                return;
            }

            if ($event === 'comment' && (!$this->sourceId ||
                !$ticket->comments()->whereKey($this->sourceId)->where('visibility', 'public')->exists())) {
                return;
            }

            if (in_array($event, ['comment', 'status'], true) && !$this->sourceId) {
                return;
            }

            $automations = app(TicketWhatsAppAutomations::class);
            if (!$automations->enabled($event) || !$connection->values()['api_key_saved']) {
                return;
            }

            if ($column === null) {
                DB::table('whatsapp_notification_deliveries')->insertOrIgnore([
                    'ticket_id' => $ticket->id,
                    'event' => $event,
                    'event_key' => (string) $this->sourceId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (DB::table('whatsapp_notification_deliveries')
                    ->where('event', $event)->where('event_key', (string) $this->sourceId)
                    ->whereNotNull('sent_at')->exists()) {
                    return;
                }
            }

            try {
                $connection->sendText(
                    $ticket->requester_whatsapp,
                    $automations->render($event, $ticket, $this->statusName),
                );
                if ($column) {
                    $ticket->update([$column => now()]);
                } else {
                    DB::table('whatsapp_notification_deliveries')
                        ->where('event', $event)->where('event_key', (string) $this->sourceId)
                        ->update(['sent_at' => now(), 'updated_at' => now()]);
                }
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
