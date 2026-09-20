<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TicketWhatsAppAutomations;
use Illuminate\Http\Request;
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
