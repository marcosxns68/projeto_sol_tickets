<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('active')->default(true);
        });

        Schema::table('statuses', function (Blueprint $table) {
            $table->string('system_key')->nullable()->unique();
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            $table->boolean('required')->default(true);
        });

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->enum('effect', ['allow', 'deny']);
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
        });

        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });

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
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'group' => explode('.', $key)[0], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $statusMap = [
            'Novo' => 'new',
            'Em andamento' => 'in_progress',
            'Aguardando aprovação' => 'completion_requested',
            'Resolvido' => 'resolved',
            'Fechado' => 'closed',
            'Cancelado' => 'cancelled',
        ];

        foreach ($statusMap as $name => $systemKey) {
            DB::table('statuses')->where('name', $name)->update(['system_key' => $systemKey]);
        }

        $forwarded = DB::table('statuses')->where('name', 'Encaminhado')->first();
        if ($forwarded) {
            DB::table('statuses')->where('id', $forwarded->id)->update([
                'system_key' => 'forwarded',
                'category' => 'open',
                'active' => true,
            ]);
        } else {
            DB::table('statuses')->insert([
                'name' => 'Encaminhado',
                'system_key' => 'forwarded',
                'category' => 'open',
                'color' => '#7C3AED',
                'position' => 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $superAdminId = DB::table('roles')->where('name', 'Super Admin')->value('id');
        if ($superAdminId) {
            $this->attachPermissions($superAdminId, array_keys($permissions));
        }

        $internalId = DB::table('roles')->where('name', 'Usuário interno')->value('id');
        if ($internalId) {
            $this->attachPermissions($internalId, [
                'tickets.create', 'tickets.view_department', 'tickets.forward', 'tickets.assume',
                'tickets.change_status', 'tickets.comment', 'tickets.internal_note',
                'tickets.manage_checklist', 'tickets.manage_attachments',
                'tickets.request_completion', 'tickets.resolve',
            ]);
        }

        $gestorId = DB::table('roles')->where('name', 'Gestor')->value('id');
        if (!$gestorId) {
            $gestorId = DB::table('roles')->insertGetId([
                'name' => 'Gestor',
                'protected' => false,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->attachPermissions($gestorId, [
            'tickets.create', 'tickets.view_department', 'tickets.view_all', 'tickets.edit',
            'tickets.forward', 'tickets.assume', 'tickets.reassign', 'tickets.change_status',
            'tickets.change_priority', 'tickets.change_due_date', 'tickets.manage_participants',
            'tickets.manage_labels', 'tickets.comment', 'tickets.internal_note',
            'tickets.manage_checklist', 'tickets.manage_attachments', 'tickets.request_completion',
            'tickets.resolve', 'tickets.close', 'tickets.cancel', 'tickets.trash',
            'tickets.restore', 'tickets.recurrence', 'users.manage', 'departments.manage',
            'statuses.manage', 'labels.manage', 'audit.view',
        ]);
    }

    private function attachPermissions(int $roleId, array $keys): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        $rows = $permissionIds->map(fn ($permissionId) => [
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ])->all();

        if ($rows) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('user_permission_overrides');

        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn('required');
        });

        Schema::table('statuses', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropColumn('system_key');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
