<?php

namespace App\Services;

use App\Jobs\SendDepartmentWhatsApp;
use App\Jobs\SendDepartmentWebPush;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class DepartmentNotifications
{
    /**
     * Cada canal é opt-in por departamento; não envia ao autor ou ao próprio
     * solicitante. Responsável mantém avisos próprios independentes.
     */
    public function emit(Ticket $ticket, string $event, ?User $actor = null, ?string $actorEmail = null): void
    {
        if (!$ticket->department_id || !in_array($event, ['created', 'entered', 'replied', 'cancelled'], true)) {
            return;
        }

        $ticket->loadMissing('department');
        $department = $ticket->department;
        if (!$department) {
            return;
        }

        $headlines = [
            'created' => 'Novo ticket em '.$department->name,
            'entered' => 'Ticket encaminhado para '.$department->name,
            'replied' => 'Cliente respondeu em '.$department->name,
            'cancelled' => 'Ticket cancelado em '.$department->name,
        ];
        $message = match ($event) {
            'created' => 'Um novo ticket entrou na caixa '.$department->name.'.',
            'entered' => 'O ticket foi encaminhado para a caixa '.$department->name.'.',
            'cancelled' => 'Um ticket desta caixa foi cancelado.',
            default => 'O solicitante adicionou uma resposta pública ao ticket da caixa '.$department->name.'.',
        };

        $subscriptions = app(DepartmentSubscriptions::class)->recipients($department);
        $access = app(DepartmentAccess::class);

        $eventKey = implode(':', [$ticket->id, $department->id, $event,
            $ticket->updated_at?->format('YmdHis.u') ?? now()->format('YmdHis.u')]);
        foreach ($subscriptions as $subscriber) {
            $userId = (int) $subscriber->id;
            $email = strtolower(trim((string) $subscriber->email));
            if (($actor && $userId === (int) $actor->id)
                || ($actorEmail && $email === strtolower(trim($actorEmail)))
                || ($ticket->requester_user_id && $userId === (int) $ticket->requester_user_id)
                || ($ticket->requester_email && $email === strtolower(trim($ticket->requester_email)))) {
                continue;
            }

            $user = User::find($userId);
            if (!$user || !$user->active || !$access->canView($user, $department)) {
                continue;
            }

            $legacySelection = (bool) ($subscriber->legacy_selection ?? false);
            $channels = [];
            if ($subscriber->notify_push || $legacySelection) {
                $channels[] = 'database';
            }
            if ($subscriber->notify_email || $legacySelection) {
                $channels[] = 'mail';
            }

            $alreadyNotifiedDirectly = $event === 'created'
                && ($ticket->assignee_id === $userId || $ticket->creator_id === $userId
                    || $ticket->participants()->where('users.id', $userId)->exists());
            $alreadyNotifiedDirectly = $alreadyNotifiedDirectly
                || ($event === 'replied' && $ticket->assignee_id === $userId)
                || ($event === 'replied' && $ticket->participants()
                    ->where('users.id', $userId)
                    ->wherePivot('notify_comments', true)->exists());

            if ($channels !== [] && !$alreadyNotifiedDirectly) {
                try {
                    if (in_array('mail', $channels, true)) {
                        app(MailSettings::class)->apply();
                    }
                    $user->notify(new TicketActivityNotification(
                        $ticket, $headlines[$event], $message, route('tickets.show', $ticket),
                        'ticket.department.'.$event, $actor?->name,
                        $channels, $department->id,
                    ));
                } catch (Throwable $exception) {
                    Log::warning('Falha no aviso de caixa.', [
                        'ticket_id' => $ticket->id, 'department_id' => $department->id,
                        'user_id' => $userId, 'exception_class' => $exception::class,
                    ]);
                }
            }

            if ($subscriber->notify_push) {
                SendDepartmentWebPush::dispatch(
                    $ticket->id, $department->id, $userId, $event
                )->afterCommit();
            }

            if ($subscriber->notify_whatsapp
                && !($event === 'replied' && $ticket->assignee_id === $userId)
                && $user->whatsapp_reply_enabled
                && WhatsAppConnection::normalizeNumber($user->whatsapp) !== null) {
                SendDepartmentWhatsApp::dispatch(
                    $ticket->id, $department->id, $userId, $event, $eventKey,
                )->afterCommit();
            }
        }
    }
}
