<?php

namespace App\Notifications;

use App\Models\Department;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DepartmentMembershipNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Department $department,
        public string $accessLevel,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$label, $description] = match ($this->accessLevel) {
            'edit' => [
                'Editar tickets',
                'Esse nível inclui enviar, visualizar e editar tickets do departamento, respeitando as permissões gerais da sua conta.',
            ],
            'view' => [
                'Visualizar tickets',
                'Esse nível inclui enviar tickets e visualizar todos os tickets do departamento.',
            ],
            default => [
                'Enviar tickets',
                'Esse nível permite criar e encaminhar tickets para o departamento, sem visualizar os tickets existentes.',
            ],
        };

        return (new MailMessage)
            ->subject('Você foi adicionado ao departamento '.$this->department->name)
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Você foi adicionado ao departamento '.$this->department->name.' no Sutoorii Tickets.')
            ->line('Nível de acesso: '.$label.'.')
            ->line($description)
            ->salutation('Atenciosamente, Sutoorii Tickets');
    }
}
