<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\User;
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

class SendAssigneeReplyWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Uma resposta ambígua da Evolution API não deve duplicar a mensagem.
    public int $tries = 1;
    public int $timeout = 25;

    public function __construct(
        public int $ticketId,
        public int $commentId,
        public int $assigneeId,
    ) {}

    public function handle(WhatsAppConnection $connection, TicketWhatsAppAutomations $automations): void
    {
        if (!$automations->enabled('responsible_reply')) {
            return;
        }

        $eventKey = sha1($this->ticketId.':'.$this->commentId.':'.$this->assigneeId);
        Cache::lock('tickets.whatsapp.responsible_reply.'.$eventKey, 40)
            ->block(5, function () use ($connection, $automations, $eventKey) {
                $ticket = Ticket::with('status')->find($this->ticketId);
                $assignee = User::find($this->assigneeId);

                if (!$ticket || !$assignee || !$assignee->active
                    || (int) $ticket->assignee_id !== (int) $assignee->id
                    || !$assignee->whatsapp_reply_enabled
                    || !$connection->values()['api_key_saved']) {
                    return;
                }

                $phone = WhatsAppConnection::normalizeNumber($assignee->whatsapp);
                if ($phone === null) {
                    return;
                }

                $comment = $ticket->comments()->whereKey($this->commentId)
                    ->where('visibility', 'public')->first();
                if (!$comment) {
                    return;
                }

                $requesterEmail = strtolower(trim((string) $ticket->requester_email));
                if ($comment->user_id === $assignee->id ||
                    ($requesterEmail !== '' && strtolower(trim($assignee->email)) === $requesterEmail)) {
                    return;
                }

                // Somente uma resposta do solicitante pode avisar o responsável.
                $isRequester = $comment->source === 'integration' ||
                    $comment->source === 'requester' ||
                    ($ticket->requester_user_id && $comment->user_id === $ticket->requester_user_id);
                if (!$isRequester) {
                    return;
                }

                $alreadySent = DB::table('whatsapp_notification_deliveries')
                    ->where('event', 'responsible_reply')
                    ->where('event_key', $eventKey)
                    ->whereNotNull('sent_at')->exists();
                if ($alreadySent) {
                    return;
                }

                DB::table('whatsapp_notification_deliveries')->insertOrIgnore([
                    'ticket_id' => $ticket->id,
                    'event' => 'responsible_reply',
                    'event_key' => $eventKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                try {
                    // Nunca incluir corpo do comentário ou número de telefone nos logs.
                    $connection->sendText($phone, $automations->render('responsible_reply', $ticket));
                    DB::table('whatsapp_notification_deliveries')
                        ->where('event', 'responsible_reply')
                        ->where('event_key', $eventKey)
                        ->update(['sent_at' => now(), 'updated_at' => now()]);
                } catch (Throwable $exception) {
                    Log::warning('Falha ao enviar aviso WhatsApp ao responsável.', [
                        'ticket_id' => $ticket->id,
                        'comment_id' => $this->commentId,
                        'assignee_id' => $assignee->id,
                        'exception_class' => $exception::class,
                    ]);
                }
            });
    }
}
