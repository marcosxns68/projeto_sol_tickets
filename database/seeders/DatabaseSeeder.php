<?php

namespace Database\Seeders;

use App\Models\Label;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'tickets.create' => 'Criar tickets',
            'tickets.view_department' => 'Visualizar tickets do próprio departamento',
            'tickets.view_all' => 'Visualizar todos os tickets',
            'tickets.edit' => 'Editar título e descrição',
            'tickets.forward' => 'Encaminhar entre departamentos',
            'tickets.assume' => 'Assumir ticket sem responsável',
            'tickets.reassign' => 'Reatribuir responsável',
            'tickets.change_status' => 'Alterar status',
            'tickets.change_priority' => 'Alterar prioridade',
            'tickets.change_due_date' => 'Alterar prazo',
            'tickets.manage_participants' => 'Gerenciar colaboradores e seguidores',
            'tickets.manage_labels' => 'Gerenciar etiquetas do ticket',
            'tickets.comment' => 'Adicionar comentários públicos',
            'tickets.internal_note' => 'Adicionar notas internas',
            'tickets.manage_checklist' => 'Gerenciar checklist',
            'tickets.manage_attachments' => 'Gerenciar anexos',
            'tickets.request_completion' => 'Solicitar conclusão',
            'tickets.resolve' => 'Resolver tickets',
            'tickets.close' => 'Fechar tickets',
            'tickets.cancel' => 'Cancelar tickets',
            'tickets.trash' => 'Enviar tickets para lixeira',
            'tickets.restore' => 'Restaurar tickets da lixeira',
            'tickets.force_delete' => 'Excluir tickets definitivamente',
            'tickets.recurrence' => 'Gerenciar recorrência',
            'users.manage' => 'Gerenciar usuários',
            'roles.manage' => 'Gerenciar cargos',
            'permissions.manage' => 'Gerenciar permissões',
            'departments.manage' => 'Gerenciar departamentos',
            'statuses.manage' => 'Gerenciar status',
            'labels.manage' => 'Gerenciar etiquetas',
            'integrations.manage' => 'Gerenciar integrações',
            'audit.view' => 'Visualizar auditoria',
            'settings.manage' => 'Gerenciar configurações',
        ];

        foreach ($permissions as $key => $name) {
            Permission::updateOrCreate(['key' => $key], [
                'name' => $name,
                'group' => explode('.', $key)[0],
            ]);
        }

        $super = Role::firstOrCreate(['name' => 'Super Admin'], ['protected' => true, 'active' => true]);
        $super->permissions()->sync(Permission::pluck('id'));

        $basic = Role::firstOrCreate(['name' => 'Usuário interno'], ['protected' => true, 'active' => true]);
        $basic->permissions()->sync(Permission::whereIn('key', [
            'tickets.create', 'tickets.view_department', 'tickets.forward', 'tickets.assume',
            'tickets.change_status', 'tickets.comment', 'tickets.internal_note',
            'tickets.manage_checklist', 'tickets.manage_attachments',
            'tickets.request_completion', 'tickets.resolve',
        ])->pluck('id'));

        $gestor = Role::firstOrCreate(['name' => 'Gestor'], ['protected' => false, 'active' => true]);
        $gestor->permissions()->sync(Permission::whereIn('key', [
            'tickets.create', 'tickets.view_department', 'tickets.view_all', 'tickets.edit',
            'tickets.forward', 'tickets.assume', 'tickets.reassign', 'tickets.change_status',
            'tickets.change_priority', 'tickets.change_due_date', 'tickets.manage_participants',
            'tickets.manage_labels', 'tickets.comment', 'tickets.internal_note',
            'tickets.manage_checklist', 'tickets.manage_attachments', 'tickets.request_completion',
            'tickets.resolve', 'tickets.close', 'tickets.cancel', 'tickets.trash',
            'tickets.restore', 'tickets.recurrence', 'users.manage', 'departments.manage',
            'statuses.manage', 'labels.manage', 'audit.view',
        ])->pluck('id'));

        $statuses = [
            ['Novo', 'new', 'open', '#7C3AED'],
            ['Encaminhado', 'forwarded', 'open', '#7C3AED'],
            ['Em análise', null, 'open', '#2563EB'],
            ['Em andamento', 'in_progress', 'in_progress', '#0891B2'],
            ['Aguardando cliente', null, 'waiting', '#D97706'],
            ['Aguardando terceiro', null, 'waiting', '#D97706'],
            ['Aguardando aprovação', 'completion_requested', 'completion_requested', '#9333EA'],
            ['Resolvido', 'resolved', 'completed', '#16A34A'],
            ['Fechado', 'closed', 'completed', '#15803D'],
            ['Cancelado', 'cancelled', 'cancelled', '#64748B'],
        ];

        foreach ($statuses as $position => [$name, $systemKey, $category, $color]) {
            Status::updateOrCreate(['name' => $name], [
                'system_key' => $systemKey,
                'category' => $category,
                'color' => $color,
                'position' => $position,
                'active' => true,
            ]);
        }

        Label::firstOrCreate(['name' => 'Atrasada'], ['color' => '#DC2626', 'system' => true]);
    }
}
