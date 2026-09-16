<?php

namespace App\Notifications;

use App\Models\Ticket;
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
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
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

        return $mail->line('Sutoorii Tickets');
    }
}
