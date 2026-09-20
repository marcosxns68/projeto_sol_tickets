<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TicketWhatsAppAutomations
{
    // Cada nova automação deve ser cadastrada aqui para aparecer na página
    // e utilizar as mesmas regras de habilitação e edição de mensagens.
    public const DEFAULT_TEMPLATES = [
        'opened' => "> Sutoorii Tickets\n\nSeu ticket de número {numero} foi aberto com sucesso.",
        'closed' => "> Sutoorii Tickets\n\nSeu ticket de número {numero} foi fechado.",
    ];

    public const LABELS = [
        'opened' => 'Abertura do ticket',
        'closed' => 'Fechamento do ticket',
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
        // A abertura já existia antes desta página; preservamos sua configuração
        // original. O fechamento começa desligado até ativação explícita.
        return (bool) Setting::getValue('whatsapp.automation.'.$event.'.enabled', $event === 'opened');
    }

    public function template(string $event): string
    {
        $this->ensureEvent($event);

        return (string) Setting::getValue(
            'whatsapp.automation.'.$event.'.message',
            self::DEFAULT_TEMPLATES[$event],
        );
    }

    public function render(string $event, string $number): string
    {
        return str_replace('{numero}', $number, $this->template($event));
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
            $rows[$event] = [
                'label' => $label,
                'enabled' => $this->enabled($event),
                'message' => $this->template($event),
            ];
        }

        return $rows;
    }
}
