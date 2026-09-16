<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartmentWorkspaceV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, array $permissions = []): User
    {
        $role = Role::create(['name' => $name.' '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function access(User $user, Department $department, string $level, bool $follow = false): void
    {
        DB::table('department_user_access')->insert([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => $level,
            'follow_department' => $follow,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ticket(Department $department, string $statusKey): void
    {
        Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Resumo '.$statusKey,
            'description' => 'Teste',
            'priority' => 'normal',
            'status_id' => Status::system($statusKey)->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_department_manager_sees_summary_and_can_associate_user(): void
    {
        $manager = $this->user('Gestor', ['departments.manage']);
        $person = $this->user('Pessoa Associada');
        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $this->ticket($department, 'new');
        $this->ticket($department, 'closed');

        $this->actingAs($manager)->get('/departamentos')
            ->assertOk()
            ->assertSee('Suporte')
            ->assertSee('Abertos')
            ->assertSee('Concluídos');

        $this->actingAs($manager)->post('/admin/departamentos/'.$department->id.'/usuarios', [
            'user_id' => $person->id,
            'access_level' => 'view',
        ])->assertRedirect();

        $this->assertDatabaseHas('department_user_access', [
            'user_id' => $person->id,
            'department_id' => $department->id,
            'access_level' => 'view',
            'follow_department' => false,
        ]);
    }

    public function test_send_only_user_sees_department_without_ticket_counts_and_cannot_follow(): void
    {
        $user = $this->user('Somente Envio');
        $department = Department::create(['name' => 'Financeiro', 'active' => true]);
        $this->access($user, $department, 'send');
        $this->ticket($department, 'new');

        $this->actingAs($user)->get('/departamentos')
            ->assertOk()
            ->assertSee('Financeiro')
            ->assertSee('Sem acesso aos tickets');

        $this->actingAs($user)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'follow_department' => 1,
        ])->assertForbidden();
    }

    public function test_view_user_can_follow_and_downgrade_to_send_turns_follow_off(): void
    {
        $manager = $this->user('Gestor Departamentos', ['departments.manage']);
        $user = $this->user('Visualizador');
        $department = Department::create(['name' => 'Desenvolvimento', 'active' => true]);
        $this->access($user, $department, 'view');

        $this->actingAs($user)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'follow_department' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('department_user_access', [
            'user_id' => $user->id,
            'department_id' => $department->id,
            'follow_department' => true,
        ]);

        $this->actingAs($manager)->patch('/admin/departamentos/'.$department->id.'/usuarios/'.$user->id, [
            'access_level' => 'send',
        ])->assertRedirect();

        $this->assertDatabaseHas('department_user_access', [
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => 'send',
            'follow_department' => false,
        ]);
    }

    public function test_user_search_returns_only_active_matching_users(): void
    {
        $actor = $this->user('Buscador');
        $this->user('Mariana Silva');
        $inactive = $this->user('Mariana Inativa');
        $inactive->update(['active' => false]);

        $this->actingAs($actor)->getJson('/usuarios/buscar?q=Mariana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mariana Silva');
    }
}
