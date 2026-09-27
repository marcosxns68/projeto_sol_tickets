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
        'responsible_reply' => "> Sutoorii Tickets\n\nUm cliente respondeu ao ticket #{numero} pelo sistema de suporte.\nAssunto: {assunto}",
        'department_created' => "> Sutoorii Tickets\n\nNovo ticket na caixa: {departamento}\nTicket #{numero}\nAssunto: {assunto}",
        'department_entered' => "> Sutoorii Tickets\n\nTicket encaminhado para a caixa: {departamento}\nTicket #{numero}\nAssunto: {assunto}",
        'department_replied' => "> Sutoorii Tickets\n\nCliente respondeu em um ticket da caixa: {departamento}\nTicket #{numero}\nAssunto: {assunto}",
        'department_cancelled' => "> Sutoorii Tickets\n\nTicket cancelado na caixa: {departamento}\nTicket #{numero}\nAssunto: {assunto}",
    ];

    public const LABELS = [
        'opened' => 'Abertura do ticket',
        'closed' => 'Fechamento do ticket',
        'comment' => 'Novo comentário público',
        'status' => 'Mudança de status',
        'responsible_reply' => 'Resposta do solicitante ao responsável',
        'department_created' => 'Novo ticket no departamento',
        'department_entered' => 'Ticket encaminhado ao departamento',
        'department_replied' => 'Resposta em ticket do departamento',
        'department_cancelled' => 'Ticket cancelado no departamento',
    ];

    public const VARIABLES = ['numero', 'assunto', 'status', 'departamento'];

    private const DEFAULT_ENABLED = [
        'opened',
        'responsible_reply',
        'department_created',
        'department_entered',
        'department_replied',
        'department_cancelled',
    ];

    private function ensureEvent(string $event): void
    {
        if (!array_key_exists($event, self::DEFAULT_TEMPLATES)) {
            throw new InvalidArgumentException('Automação de WhatsApp inválida.');
        }
    }

    public function enabled(string $event): bool
    {
        $this->ensureEvent($event);
        // Preserva os comportamentos já ativos: abertura, resposta ao responsável
        // e avisos de departamentos acompanhados por WhatsApp.
        return (bool) Setting::getValue('whatsapp.automation.'.$event.'.enabled', in_array($event, self::DEFAULT_ENABLED, true));
    }

    public function template(string $event): string
    {
        $this->ensureEvent($event);

        $message = (string) Setting::getValue(
            'whatsapp.automation.'.$event.'.message',
            self::DEFAULT_TEMPLATES[$event]
        );

        // Essa orientação antiga não agrega informação e deve permanecer fora
        // de qualquer mensagem enviada pelo WhatsApp, inclusive das já salvas.
        return $this->removeTrackingInstruction($message);
    }

    public function render(string $event, Ticket $ticket, ?string $status = null): string
    {
        return strtr($this->template($event), [
            '{numero}' => $ticket->number,
            '{assunto}' => $ticket->title,
            '{status}' => $status ?? $ticket->status?->name ?? '',
            '{departamento}' => $ticket->department?->name ?? '',
        ]);
    }

    public function save(string $event, bool $enabled, string $message): void
    {
        $this->ensureEvent($event);
        Setting::setValue('whatsapp.automation.'.$event.'.enabled', $enabled);
        Setting::setValue('whatsapp.automation.'.$event.'.message', $this->removeTrackingInstruction($message));
    }

    private function removeTrackingInstruction(string $message): string
    {
        $message = preg_replace(
            '/Acesse\\s+(?:https?:\\/\\/)?tickets\\.sutoorii\\.com\\/?\\s+para acompanhar\\.?/iu',
            '',
            $message
        ) ?? $message;
        $message = preg_replace("/[ \\t]+\\n/u", "\n", $message) ?? $message;
        $message = preg_replace("/\\n{3,}/u", "\n\n", $message) ?? $message;

        return trim($message);
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
