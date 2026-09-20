<?php

namespace App\Services;

use App\Jobs\SendTicketOpenedWhatsApp;
use App\Jobs\SendAssigneeReplyWhatsApp;
use App\Jobs\SendTicketWhatsAppAutomation;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Throwable;

class TicketNotifier
{
    public function opened(Ticket $ticket, ?User $creator): void
    {
        $ticket->loadMissing(['requesterUser', 'assignee', 'participants', 'department']);

        $recipients = [];
        $this->addUser($recipients, $creator);
        $this->addRequester($recipients, $ticket);
        $this->addUser($recipients, $ticket->assignee);
        foreach ($ticket->participants as $participant) {
            $this->addUser($recipients, $participant);
        }
        $this->addDepartmentFollowers($recipients, $ticket);

        $this->sendMany(
            $ticket,
            $recipients,
            'Ticket criado',
            'O ticket foi registrado no Sutoorii Tickets.',
            null,
            'ticket.created',
            $creator?->name,
        );

        // Canal WhatsApp: exclusivamente a confirmação inicial ao solicitante,
        // nunca ao responsável, seguidores ou demais participantes.
        if ($ticket->requester_whatsapp && app(TicketWhatsAppAutomations::class)->enabled('opened')) {
            SendTicketOpenedWhatsApp::dispatch($ticket->id)->afterCommit();
        }
    }

    public function closed(Ticket $ticket): void
    {
        if (!app(TicketWhatsAppAutomations::class)->enabled('closed')) {
            return;
        }

        $this->fillMissingIntegrationWhatsapp($ticket);
        if ($ticket->requester_whatsapp) {
            SendTicketWhatsAppAutomation::dispatch($ticket->id, 'closed')->afterCommit();
        }
    }

    public function publicComment(Ticket $ticket, ?User $actor, array $groups, ?string $actorEmail = null): void
    {
        $ticket->loadMissing(['requesterUser', 'assignee', 'participants']);
        $recipients = [];

        if ($groups['requester'] ?? false) {
            $this->addRequester($recipients, $ticket);
        }
        if ($groups['responsible'] ?? false) {
            $this->addUser($recipients, $ticket->assignee);
        }
        if ($groups['collaborators'] ?? false) {
            foreach ($ticket->participants as $participant) {
                if ($participant->pivot?->type === 'collaborator' && $participant->pivot?->notify_comments) {
                    $this->addUser($recipients, $participant);
                }
            }
        }
        if ($groups['followers'] ?? false) {
            foreach ($ticket->participants as $participant) {
                if ($participant->pivot?->type === 'follower' && $participant->pivot?->notify_comments) {
                    $this->addUser($recipients, $participant);
                }
            }
        }

        $this->removeActor($recipients, $actor);
        $this->removeEmail($recipients, $actorEmail);
        $this->sendMany(
            $ticket,
            $recipients,
            'Novo comentário no ticket',
            'Um novo comentário público foi adicionado ao ticket.',
            $this->internalTicketUrl($ticket),
            'ticket.comment.public',
            $actor?->name,
        );
    }

    /**
     * Uma resposta pública escrita pelo solicitante avisa automaticamente a
     * equipe responsável, independentemente de caixas de seleção do formulário.
     * Nunca envia a notificação do próprio comentário ao solicitante.
     */
    public function requesterReplied(
        Ticket $ticket,
        ?User $actor = null,
        ?string $actorEmail = null,
        ?int $commentId = null,
    ): void {
        $this->publicComment($ticket, $actor, [
            'requester' => false,
            'responsible' => true,
            'collaborators' => true,
            'followers' => true,
        ], $actorEmail);

        // O canal destinado à equipe é independente do WhatsApp do solicitante
        // e da automação de respostas do suporte.
        if ($commentId === null || !$ticket->assignee_id ||
            !app(TicketWhatsAppAutomations::class)->enabled('responsible_reply')) {
            return;
        }

        $assignee = $ticket->assignee ?: $ticket->assignee()->first();
        if (!$assignee || !$assignee->active || !$assignee->whatsapp_reply_enabled ||
            WhatsAppConnection::normalizeNumber($assignee->whatsapp) === null ||
            ($actor && $assignee->id === $actor->id) ||
            ($actorEmail && strtolower(trim($assignee->email)) === strtolower(trim($actorEmail)))) {
            return;
        }

        SendAssigneeReplyWhatsApp::dispatch($ticket->id, $commentId, $assignee->id)->afterCommit();
    }

    public function publicCommentWhatsApp(Ticket $ticket, ?User $actor, int $commentId): void
    {
        // Respostas escritas pelo próprio solicitante não geram WhatsApp para ele.
        if (!$actor || (int) $ticket->requester_user_id === (int) $actor->id
            || !app(TicketWhatsAppAutomations::class)->enabled('comment')) {
            return;
        }

        $this->fillMissingIntegrationWhatsapp($ticket);
        if ($ticket->requester_whatsapp) {
            SendTicketWhatsAppAutomation::dispatch($ticket->id, 'comment', $commentId)->afterCommit();
        }
    }

    public function statusWhatsAppChanged(Ticket $ticket, int $eventId): void
    {
        // Fechamento tem uma mensagem própria. Não gerar dois avisos no mesmo evento.
        if ($ticket->status?->system_key === 'closed'
            || !app(TicketWhatsAppAutomations::class)->enabled('status')) {
            return;
        }

        $this->fillMissingIntegrationWhatsapp($ticket);
        if ($ticket->requester_whatsapp) {
            SendTicketWhatsAppAutomation::dispatch($ticket->id, 'status', $eventId, $ticket->status?->name)->afterCommit();
        }
    }

    private function fillMissingIntegrationWhatsapp(Ticket $ticket): void
    {
        if ($ticket->requester_whatsapp || !$ticket->system_id || !$ticket->external_requester_id) {
            return;
        }

        try {
            $system = $ticket->system ?: $ticket->system()->first();
            if ($system && app(IntegrationRequesterWhatsAppSync::class)->syncRequester(
                $system,
                (string) $ticket->external_requester_id
            ) > 0) {
                $ticket->refresh();
            }
        } catch (Throwable $exception) {
            Log::warning('Não foi possível atualizar WhatsApp de ticket antigo', [
                'ticket_id' => $ticket->id,
                'exception_class' => get_class($exception),
            ]);
        }
    }

    public function requesterChanged(Ticket $ticket, ?User $actor, string $headline = 'Ticket atualizado'): void
    {
        $ticket->loadMissing('requesterUser');
        $recipients = [];
        $this->addRequester($recipients, $ticket);
        $this->removeActor($recipients, $actor);

        $this->sendMany(
            $ticket,
            $recipients,
            $headline,
            'Houve uma atualização no seu ticket.',
            $this->requesterActionUrl($ticket),
            'ticket.requester.updated',
            $actor?->name,
        );
    }

    public function statusChanged(Ticket $ticket, ?User $actor, string $headline = 'Status do ticket atualizado'): void
    {
        $ticket->loadMissing(['assignee', 'participants', 'department']);
        $recipients = [];
        $this->addUser($recipients, $ticket->assignee);
        foreach ($ticket->participants as $participant) {
            if ($participant->pivot?->notify_status) {
                $this->addUser($recipients, $participant);
            }
        }
        $this->addDepartmentFollowers($recipients, $ticket);
        $this->removeActor($recipients, $actor);

        $this->sendMany(
            $ticket,
            $recipients,
            $headline,
            'O status do ticket foi alterado para '.($ticket->status?->name ?? 'um novo status').'.',
            $this->internalTicketUrl($ticket),
            'ticket.status.changed',
            $actor?->name,
        );
    }

    public function attachmentAdded(Ticket $ticket, ?User $actor, string $fileName): void
    {
        $ticket->loadMissing(['assignee', 'participants']);
        $recipients = [];
        $this->addUser($recipients, $ticket->assignee);
        foreach ($ticket->participants as $participant) {
            if ($participant->pivot?->notify_attachments) {
                $this->addUser($recipients, $participant);
            }
        }
        $this->removeActor($recipients, $actor);

        $this->sendMany(
            $ticket,
            $recipients,
            'Novo anexo no ticket',
            'O arquivo "'.$fileName.'" foi anexado ao ticket.',
            $this->internalTicketUrl($ticket),
            'ticket.attachment.added',
            $actor?->name,
        );
    }

    public function deadlineApproaching(Ticket $ticket): void
    {
        $ticket->loadMissing(['assignee', 'participants', 'department']);
        $recipients = [];
        $this->addUser($recipients, $ticket->assignee);
        foreach ($ticket->participants as $participant) {
            if ($participant->pivot?->notify_status) {
                $this->addUser($recipients, $participant);
            }
        }
        $this->addDepartmentFollowers($recipients, $ticket);

        $this->sendMany(
            $ticket,
            $recipients,
            'Prazo do ticket se aproxima',
            'O prazo deste ticket vence em até 24 horas.',
            $this->internalTicketUrl($ticket),
            'ticket.deadline.approaching',
            null,
        );
    }

    public function reassigned(Ticket $ticket, ?User $oldAssignee, ?User $newAssignee, ?User $actor): void
    {
        $recipients = [];
        $this->addUser($recipients, $oldAssignee);
        $this->addUser($recipients, $newAssignee);
        $this->removeActor($recipients, $actor);

        $this->sendMany(
            $ticket,
            $recipients,
            'Responsável alterado',
            'A responsabilidade pelo ticket foi alterada.',
            $this->internalTicketUrl($ticket),
            'ticket.assignee.changed',
            $actor?->name,
        );
    }

    public function participantChanged(Ticket $ticket, User $participant, string $type, bool $added, ?User $actor): void
    {
        $recipients = [];
        $this->addUser($recipients, $participant);
        $this->removeActor($recipients, $actor);

        $role = $type === 'collaborator' ? 'colaborador' : 'seguidor';
        $this->sendMany(
            $ticket,
            $recipients,
            $added ? 'Você foi adicionado ao ticket' : 'Você foi removido do ticket',
            $added
                ? 'Você foi adicionado como '.$role.' deste ticket.'
                : 'Você não participa mais deste ticket como '.$role.'.',
            $added ? $this->internalTicketUrl($ticket) : null,
            $added ? 'ticket.participant.added' : 'ticket.participant.removed',
            $actor?->name,
        );
    }

    public function departmentEvent(Ticket $ticket, string $event, ?User $actor = null): void
    {
        $recipients = [];
        $this->addDepartmentFollowers($recipients, $ticket);
        $this->removeActor($recipients, $actor);

        $headlines = [
            'created' => 'Novo ticket no departamento',
            'entered' => 'Ticket encaminhado ao departamento',
            'cancelled' => 'Ticket cancelado no departamento',
        ];

        $this->sendMany(
            $ticket,
            $recipients,
            $headlines[$event] ?? 'Atualização no departamento',
            'O ticket teve uma movimentação no departamento que você acompanha.',
            $this->internalTicketUrl($ticket),
            'ticket.department.'.$event,
            $actor?->name,
        );
    }

    private function addRequester(array &$recipients, Ticket $ticket): void
    {
        $actionUrl = $this->requesterActionUrl($ticket);

        if ($ticket->requesterUser) {
            $this->addUser($recipients, $ticket->requesterUser);
            $email = $this->normalizeEmail($ticket->requesterUser->email);
            if ($email !== null && isset($recipients[$email])) {
                $recipients[$email]['action_url'] = $actionUrl;
            }
            return;
        }

        $email = $this->normalizeEmail($ticket->requester_email);
        if ($email !== null) {
            $recipients[$email] = [
                'email' => $email,
                'user' => null,
                'action_url' => $actionUrl,
            ];
        }
    }

    private function addDepartmentFollowers(array &$recipients, Ticket $ticket): void
    {
        if (!$ticket->department_id) {
            return;
        }

        $followers = User::query()
            ->where('users.active', true)
            ->join('department_user_access', 'department_user_access.user_id', '=', 'users.id')
            ->where('department_user_access.department_id', $ticket->department_id)
            ->where('department_user_access.follow_department', true)
            ->whereIn('department_user_access.access_level', ['view', 'edit'])
            ->select('users.*')
            ->get();

        foreach ($followers as $follower) {
            $this->addUser($recipients, $follower);
        }
    }

    private function addUser(array &$recipients, ?User $user): void
    {
        if (!$user || !$user->active) {
            return;
        }

        $email = $this->normalizeEmail($user->email);
        if ($email === null) {
            return;
        }

        $existingAction = $recipients[$email]['action_url'] ?? null;
        $recipients[$email] = [
            'email' => $email,
            'user' => $user,
            'action_url' => $existingAction,
        ];
    }

    private function removeActor(array &$recipients, ?User $actor): void
    {
        $this->removeEmail($recipients, $actor?->email);
    }

    private function removeEmail(array &$recipients, ?string $email): void
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized !== null) {
            unset($recipients[$normalized]);
        }
    }

    private function sendMany(
        Ticket $ticket,
        array $recipients,
        string $headline,
        string $message,
        ?string $actionUrl,
        string $event = 'ticket.activity',
        ?string $actorName = null,
    ): void {
        // Chamadas da API não usam o middleware web; aplicar SMTP salvo também nelas.
        try {
            app(MailSettings::class)->apply();
        } catch (Throwable $exception) {
            Log::warning('Não foi possível aplicar as configurações de e-mail.', [
                'ticket_id' => $ticket->id,
                'exception' => $exception::class,
            ]);
        }

        foreach ($recipients as $recipient) {
            try {
                $notification = new TicketActivityNotification(
                    $ticket,
                    $headline,
                    $message,
                    $recipient['action_url'] ?? $actionUrl,
                    $event,
                    $actorName,
                );
                if ($recipient['user'] instanceof User) {
                    $recipient['user']->notify($notification);
                } else {
                    Notification::route('mail', $recipient['email'])->notify($notification);
                }
            } catch (Throwable $exception) {
                Log::warning('Falha ao enviar notificação de ticket.', [
                    'ticket_id' => $ticket->id,
                    'exception' => $exception::class,
                    'code' => $exception->getCode(),
                ]);
            }
        }
    }

    private function internalTicketUrl(Ticket $ticket): ?string
    {
        return Route::has('tickets.show') ? route('tickets.show', $ticket) : null;
    }

    private function requesterActionUrl(Ticket $ticket): ?string
    {
        if ($ticket->requester_user_id) {
            return $this->internalTicketUrl($ticket);
        }

        $email = $this->normalizeEmail($ticket->requester_email);
        if ($email !== null && Route::has('requester.show')) {
            return URL::signedRoute('requester.show', [
                'ticket' => $ticket->id,
                'email' => $email,
            ]);
        }

        return null;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $value = strtolower(trim((string) $email));
        return $value !== '' ? $value : null;
    }
}
