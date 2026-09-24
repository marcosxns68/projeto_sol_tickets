<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public string $headline,
        public string $message,
        public ?string $actionUrl = null,
        public string $event = 'ticket.activity',
        public ?string $actorName = null,
        public ?array $channels = null,
        public ?int $departmentId = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $this->channels ?? ($notifiable instanceof User ? ['database', 'mail'] : ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket->loadMissing(['status', 'department']);

        $mail = (new MailMessage)
            ->subject('[#'.$ticket->number.'] '.$this->headline)
            ->greeting('Olá!')
            ->line($this->message)
            ->line('Ticket: #'.$ticket->number.' — '.$ticket->title)
            ->line('Status: '.($ticket->status?->name ?? 'Não definido'));

        if ($ticket->department) {
            $mail->line('Departamento: '.$ticket->department->name);
        }

        if ($this->actionUrl) {
            $mail->action('Abrir ticket', $this->actionUrl);
        }

        return $mail->salutation('Atenciosamente, Sutoorii Tickets');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->number,
            'event' => $this->event,
            'department_id' => $this->departmentId,
            'title' => $this->headline,
            'message' => $this->message,
            'actor_name' => $this->actorName,
            'url' => $this->actionUrl,
        ];
    }
}
