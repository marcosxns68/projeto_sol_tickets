<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyAssignedTicketsSummary extends Notification
{
    use Queueable;

    /**
     * @param array<int, array{number: string, title: string, due_at: ?string}> $tickets
     */
    public function __construct(
        public int $total,
        public array $tickets,
        public string $date,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Seu resumo diário: '.$this->total.' tickets em aberto')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Resumo de '.$this->date.' do Sutoorii Tickets.')
            ->line('Você tem '.$this->total.' tickets em aberto atribuídos a você.');

        foreach ($this->tickets as $ticket) {
            $line = '#'.$ticket['number'].' — '.$ticket['title'];
            if ($ticket['due_at']) {
                $line .= ' · prazo '.$ticket['due_at'];
            }
            $mail->line($line);
        }

        if ($this->total > count($this->tickets)) {
            $mail->line('Consulte os demais tickets no sistema.');
        }

        return $mail
            ->action('Ver meus tickets', route('boxes.mine', ['relation' => 'assignee']))
            ->salutation('Atenciosamente, Sutoorii Tickets');
    }
}
