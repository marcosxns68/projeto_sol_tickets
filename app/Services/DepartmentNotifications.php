<?php

namespace App\Services;

use App\Jobs\SendDepartmentWhatsApp;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Support\Facades\DB;
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

        $subscriptions = DB::table('department_user_access')
            ->join('users', 'users.id', '=', 'department_user_access.user_id')
            ->where('users.active', true)
            ->where('department_user_access.department_id', $department->id)
            ->where('department_user_access.follow_department', true)
            ->whereIn('department_user_access.access_level', ['view', 'edit'])
            ->select([
                'users.id', 'users.email',
                'department_user_access.notify_email',
                'department_user_access.notify_whatsapp',
                'department_user_access.notify_push',
            ])
            ->get();

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
            if (!$user || !$user->active) {
                continue;
            }

            // Acompanhamentos antigos criados pelo formulário anterior não
            // possuíam escolhas individuais: preservar e-mail e sininho.
            $legacySelection = !$subscriber->notify_email
                && !$subscriber->notify_whatsapp && !$subscriber->notify_push;
            $channels = [];
            if ($subscriber->notify_push || $legacySelection) {
                $channels[] = 'database';
            }
            if ($subscriber->notify_email || $legacySelection) {
                $channels[] = 'mail';
            }

            // Evitar segundo aviso no sininho/e-mail por papel no MESMO evento.
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

            // Se o usuário já é o responsável, a resposta do cliente gera o
            // WhatsApp próprio do responsável. Evitar aviso duplo pelo depto.
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
