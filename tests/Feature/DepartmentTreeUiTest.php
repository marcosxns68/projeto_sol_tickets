<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketFolder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentTreeUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function manager(): User
    {
        $role = Role::create(['name' => 'Gestor árvore '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::whereIn('key', ['departments.manage', 'tickets.view_all', 'tickets.create'])->pluck('id'));

        return User::create([
            'name' => 'Gestor Árvore',
            'email' => uniqid('tree-manager-').'@sutoorii.test',
            'password' => 'SenhaTeste123!',
            'email_verified_at' => now(),
            'active' => true,
            'role_id' => $role->id,
        ]);
    }

    public function test_departments_page_renders_compact_tree_actions_description_and_access_hierarchy(): void
    {
        $manager = $this->manager();
        $department = Department::create([
            'name' => 'Desenvolvimento',
            'description' => 'Projetos, sistemas e produto',
            'active' => true,
        ]);
        $folder = TicketFolder::create(['department_id' => $department->id, 'name' => 'Afialo']);
        Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Checkout urgente',
            'description' => 'Teste',
            'priority' => 'urgent',
            'status_id' => Status::system('new')->id,
            'department_id' => $department->id,
            'folder_id' => $folder->id,
        ]);

        $response = $this->actingAs($manager)->get('/departamentos')->assertOk();

        $response->assertSee('+ Novo departamento')
            ->assertSee('department-tree', false)
            ->assertSee('department-tree-row', false)
            ->assertSee('Projetos, sistemas e produto')
            ->assertSee('Nova tarefa')
            ->assertSee('Nova pasta')
            ->assertSee('Acompanhar e notificações')
            ->assertSee('Pessoas e acessos')
            ->assertSee('Editar departamento')
            ->assertSee('Nível 1 — Enviar')
            ->assertSee('Nível 2 — Visualizar')
            ->assertSee('Nível 3 — Editar')
            ->assertSee('Hierarquia: Enviar → Visualizar → Editar. Cada nível inclui o anterior.')
            ->assertSee('data-priority="urgent"', false)
            ->assertSee('aria-label="Prioridade: Urgente"', false)
            ->assertSee('#DC2626', false)
            ->assertDontSee('clique no nome do departamento', false);
    }

    public function test_department_description_is_created_and_updated(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post('/admin/departamentos', [
            'name' => 'Produto',
            'description' => 'Descrição inicial',
            'active' => 1,
        ])->assertRedirect('/admin/departamentos');

        $department = Department::where('name', 'Produto')->firstOrFail();
        $this->assertSame('Descrição inicial', $department->description);

        $this->actingAs($manager)->patch('/admin/departamentos/'.$department->id, [
            'name' => 'Produto e Design',
            'description' => 'Descrição alterada',
            'active' => 1,
        ])->assertRedirect('/admin/departamentos');

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'name' => 'Produto e Design',
            'description' => 'Descrição alterada',
        ]);
    }

    public function test_send_only_view_keeps_department_compact_but_hides_ticket_tree_and_follow_action(): void
    {
        $role = Role::create(['name' => 'Envio '.uniqid(), 'active' => true]);
        $user = User::create([
            'name' => 'Somente Envio', 'email' => uniqid('send-').'@sutoorii.test',
            'password' => 'SenhaTeste123!', 'email_verified_at' => now(), 'active' => true, 'role_id' => $role->id,
        ]);
        $department = Department::create(['name' => 'Financeiro árvore', 'active' => true]);
        $department->users()->attach($user->id, ['access_level' => 'send']);

        $this->actingAs($user)->get('/departamentos')
            ->assertOk()
            ->assertSee('Financeiro árvore')
            ->assertSee('Sem acesso aos tickets')
            ->assertDontSee('Acompanhar e notificações');
    }
}
