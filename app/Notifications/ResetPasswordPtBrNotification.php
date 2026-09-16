<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordPtBrNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $broker = config('auth.defaults.passwords');
        $expire = (int) config('auth.passwords.'.$broker.'.expire', 60);

        return (new MailMessage)
            ->subject('Redefinição de senha — Sutoorii Tickets')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos uma solicitação para redefinir a senha da sua conta no Sutoorii Tickets.')
            ->action('Redefinir senha', $resetUrl)
            ->line('Este link de redefinição expira em '.$expire.' minutos.')
            ->line('Se você não solicitou a redefinição de senha, pode ignorar esta mensagem.')
            ->salutation('Atenciosamente, Sutoorii Tickets');
    }
}
