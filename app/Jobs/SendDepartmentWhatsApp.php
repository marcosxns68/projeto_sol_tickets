<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\User;
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

class SendDepartmentWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 25;

    public function __construct(
        public int $ticketId,
        public int $departmentId,
        public int $userId,
        public string $event,
        public string $eventKey,
    ) {}

    public function handle(WhatsAppConnection $connection): void
    {
        if (!in_array($this->event, ['created', 'entered', 'replied', 'cancelled'], true)) {
            return;
        }

        $lockKey = hash('sha256', $this->eventKey.':'.$this->userId);
        Cache::lock('tickets.wa.department.'.$lockKey, 40)->block(5, function () use ($connection, $lockKey) {
            $ticket = Ticket::with('department')->find($this->ticketId);
            $user = User::find($this->userId);
            if (!$ticket || !$user || !$user->active
                || (int) $ticket->department_id !== $this->departmentId) {
                return;
            }

            $subscription = DB::table('department_user_access')
                ->where('department_id', $this->departmentId)
                ->where('user_id', $user->id)
                ->whereIn('access_level', ['view', 'edit'])
                ->where('follow_department', true)
                ->where('notify_whatsapp', true)
                ->first();

            if (!$subscription || !$user->whatsapp_reply_enabled) {
                return;
            }

            $phone = WhatsAppConnection::normalizeNumber($user->whatsapp);
            if (!$phone || !$connection->values()['api_key_saved']) {
                return;
            }

            $message = match ($this->event) {
                'created' => 'Novo ticket na caixa',
                'entered' => 'Ticket encaminhado para a caixa',
                'replied' => 'Cliente respondeu em um ticket da caixa',
                'cancelled' => 'Ticket cancelado na caixa',
            };

            // Evita reenvio em caso de execução repetida da mesma tarefa.
            $delivery = 'department_'.$this->event;
            $key = substr($lockKey, 0, 64);
            if (DB::table('whatsapp_notification_deliveries')
                ->where('event', $delivery)->where('event_key', $key)
                ->whereNotNull('sent_at')->exists()) {
                return;
            }

            DB::table('whatsapp_notification_deliveries')->insertOrIgnore([
                'ticket_id' => $ticket->id,
                'event' => $delivery,
                'event_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $connection->sendText($phone,
                    "> Sutoorii Tickets\n\n".$message.": ".($ticket->department?->name ?? 'Departamento')
                    ."\nTicket #".$ticket->number."\nAssunto: ".$ticket->title
                    ."\nAcesse tickets.sutoorii.com para acompanhar."
                );
                DB::table('whatsapp_notification_deliveries')
                    ->where('event', $delivery)->where('event_key', $key)
                    ->update(['sent_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $exception) {
                Log::warning('Falha no aviso WhatsApp de departamento.', [
                    'ticket_id' => $ticket->id,
                    'department_id' => $this->departmentId,
                    'user_id' => $user->id,
                    'exception_class' => $exception::class,
                ]);
            }
        });
    }
}
