<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsHubController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $superAdmin = $user?->role?->name === 'Super Admin';
        $canMail = $user->hasPermission('users.manage') && $user->hasPermission('permissions.manage');
        $canIntegrations = $user->hasPermission('integrations.manage');

        abort_unless($superAdmin || $canMail || $canIntegrations, 403);

        $sections = [];

        if ($superAdmin) {
            $sections[] = [
                'title' => 'Prazos por prioridade',
                'description' => 'Defina os prazos automáticos para prioridades baixa, normal, alta e urgente.',
                'route' => 'admin.settings.priorities.edit',
            ];
            $sections[] = [
                'title' => 'WhatsApp',
                'description' => 'Configure a conexão, a instância e o acesso ao canal de mensagens.',
                'route' => 'admin.settings.whatsapp.edit',
            ];
            $sections[] = [
                'title' => 'Configurações de notificações',
                'description' => 'Gerencie os avisos automáticos de abertura, comentário, status e fechamento.',
                'route' => 'admin.settings.notifications.edit',
            ];
        }

        if ($canMail) {
            $sections[] = [
                'title' => 'Configurações de e-mail',
                'description' => 'Ajuste o remetente, o servidor SMTP e teste os envios.',
                'route' => 'admin.settings.mail.edit',
            ];
        }

        if ($canIntegrations) {
            $sections[] = [
                'title' => 'Integrações',
                'description' => 'Cadastre sistemas clientes, configure departamentos, etiquetas e credenciais de integração.',
                'route' => 'admin.integrations.index',
            ];
        }

        return view('admin.settings.index', ['sections' => $sections]);
    }
}
