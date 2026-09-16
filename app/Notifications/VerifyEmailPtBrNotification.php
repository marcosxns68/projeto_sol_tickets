<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailPtBrNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        return (new MailMessage)
            ->subject('Confirme seu e-mail — Sutoorii Tickets')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Para concluir seu cadastro no Sutoorii Tickets, confirme seu endereço de e-mail.')
            ->action('Confirmar e-mail', $verificationUrl)
            ->line('Se você não criou esta conta, pode ignorar esta mensagem.')
            ->salutation('Atenciosamente, Sutoorii Tickets');
    }
}
