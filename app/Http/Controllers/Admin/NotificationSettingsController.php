<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TicketWhatsAppAutomations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request, TicketWhatsAppAutomations $automations)
    {
        $this->authorizeAdmin($request);

        return view('admin.settings.notifications', [
            'automations' => $automations->configurations(),
        ]);
    }

    public function updateAll(Request $request, TicketWhatsAppAutomations $automations)
    {
        $this->authorizeAdmin($request);

        $events = array_keys(TicketWhatsAppAutomations::LABELS);
        $rules = ['automations' => ['required', 'array:'.implode(',', $events)]];
        $messages = [
            'automations.required' => 'Informe as configurações das notificações.',
        ];

        foreach ($events as $event) {
            $field = 'automations.'.$event;
            $label = TicketWhatsAppAutomations::LABELS[$event];
            $rules[$field] = ['required', 'array:enabled,message'];
            $rules[$field.'.enabled'] = ['required', 'boolean'];
            $rules[$field.'.message'] = ['required', 'string', 'max:2000'];
            $messages[$field.'.message.required'] = 'Escreva o texto da notificação: '.$label.'.';
            $messages[$field.'.message.max'] = 'A mensagem de '.$label.' deve ter no máximo 2000 caracteres.';
            $messages[$field.'.enabled.boolean'] = 'Informe se a notificação de '.$label.' está ativada.';
        }

        $data = $request->validate($rules, $messages);
        $validated = [];

        foreach ($events as $event) {
            $message = trim($data['automations'][$event]['message']);
            if ($message === '') {
                throw ValidationException::withMessages([
                    'automations.'.$event.'.message' => 'Escreva o texto da notificação: '.TicketWhatsAppAutomations::LABELS[$event].'.',
                ]);
            }
            if (preg_match_all('/\\{([^{}]+)\\}/u', $message, $matches)) {
                foreach ($matches[1] as $name) {
                    if (!in_array($name, TicketWhatsAppAutomations::VARIABLES, true)) {
                        throw ValidationException::withMessages([
                            'automations.'.$event.'.message' => 'Na mensagem de '.TicketWhatsAppAutomations::LABELS[$event].', use apenas {numero}, {assunto} e {status}.',
                        ]);
                    }
                }
            }
            $validated[$event] = [
                'enabled' => (bool) $data['automations'][$event]['enabled'],
                'message' => $message,
            ];
        }

        DB::transaction(function () use ($automations, $validated) {
            foreach ($validated as $event => $configuration) {
                $automations->save($event, $configuration['enabled'], $configuration['message']);
            }
        });

        return redirect()->route('admin.settings.notifications.edit')
            ->with('success', 'Todas as configurações de notificações foram salvas.');
    }

    public function update(Request $request, string $event, TicketWhatsAppAutomations $automations)
    {
        $this->authorizeAdmin($request);
        abort_unless(array_key_exists($event, TicketWhatsAppAutomations::LABELS), 404);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'message' => ['required', 'string', 'min:1', 'max:2000'],
        ], [
            'enabled.boolean' => 'Informe se a notificação está ativada.',
            'message.required' => 'Escreva o texto da mensagem automática.',
            'message.string' => 'O texto da mensagem é inválido.',
            'message.max' => 'A mensagem deve ter no máximo 2000 caracteres.',
        ]);

        $message = trim($data['message']);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => 'Escreva o texto da mensagem automática.']);
        }

        // Neste momento só há um campo dinâmico autorizado, evitando
        // que erros de digitação enviem variáveis não substituídas ao cliente.
        if (preg_match_all('/\{([^{}]+)\}/u', $message, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array($name, TicketWhatsAppAutomations::VARIABLES, true)) {
                    throw ValidationException::withMessages([
                        'message' => 'Use apenas as variáveis {numero}, {assunto} e {status} disponíveis nesta página.',
                    ]);
                }
            }
        }

        $automations->save($event, $request->boolean('enabled'), $message);

        return redirect()->route('admin.settings.notifications.edit')
            ->with('success', 'Mensagem e ativação de "'.TicketWhatsAppAutomations::LABELS[$event].'" atualizadas.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'Super Admin', 403);
    }
}
