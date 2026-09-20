<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Ticket;
use InvalidArgumentException;

class TicketWhatsAppAutomations
{
    // Novas automações entram neste catálogo e aparecem automaticamente na página.
    public const DEFAULT_TEMPLATES = [
        'opened' => "> Sutoorii Tickets\n\nSeu ticket de número {numero} foi aberto com sucesso.\nAssunto: {assunto}",
        'closed' => "> Sutoorii Tickets\n\nSeu ticket de número {numero} foi fechado.\nAssunto: {assunto}",
        'comment' => "> Sutoorii Tickets\n\nSeu ticket de número {numero} recebeu uma nova resposta.\nAssunto: {assunto}",
        'status' => "> Sutoorii Tickets\n\nO status do seu ticket de número {numero} foi alterado para {status}.\nAssunto: {assunto}",
    ];

    public const LABELS = [
        'opened' => 'Abertura do ticket',
        'closed' => 'Fechamento do ticket',
        'comment' => 'Novo comentário público',
        'status' => 'Mudança de status',
    ];

    public const VARIABLES = ['numero', 'assunto', 'status'];

    private function ensureEvent(string $event): void
    {
        if (!array_key_exists($event, self::DEFAULT_TEMPLATES)) {
            throw new InvalidArgumentException('Automação de WhatsApp inválida.');
        }
    }

    public function enabled(string $event): bool
    {
        $this->ensureEvent($event);
        // Preserva abertura já existente; demais avisos começam desligados.
        return (bool) Setting::getValue('whatsapp.automation.'.$event.'.enabled', $event === 'opened');
    }

    public function template(string $event): string
    {
        $this->ensureEvent($event);
        return (string) Setting::getValue('whatsapp.automation.'.$event.'.message', self::DEFAULT_TEMPLATES[$event]);
    }

    public function render(string $event, Ticket $ticket, ?string $status = null): string
    {
        return strtr($this->template($event), [
            '{numero}' => $ticket->number,
            '{assunto}' => $ticket->title,
            '{status}' => $status ?? $ticket->status?->name ?? '',
        ]);
    }

    public function save(string $event, bool $enabled, string $message): void
    {
        $this->ensureEvent($event);
        Setting::setValue('whatsapp.automation.'.$event.'.enabled', $enabled);
        Setting::setValue('whatsapp.automation.'.$event.'.message', $message);
    }

    public function configurations(): array
    {
        $rows = [];
        foreach (self::LABELS as $event => $label) {
            $rows[$event] = ['label' => $label, 'enabled' => $this->enabled($event), 'message' => $this->template($event)];
        }
        return $rows;
    }
}
