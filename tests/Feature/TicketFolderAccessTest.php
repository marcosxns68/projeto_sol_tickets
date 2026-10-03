<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketFolder;
use App\Models\User;
use App\Services\TicketFolderTree;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketFolderAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, string $role = 'Usuário interno'): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('folder-').'@sutoorii.test',
            'password' => 'SenhaTeste123!',
            'email_verified_at' => now(),
            'active' => true,
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
        ]);
    }

    private function associate(User $user, Department $department, string $level): void
    {
        DB::table('department_user_access')->insert([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => $level,
            'follow_department' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_edit_member_can_create_root_folder_and_subfolder(): void
    {
        $department = Department::create(['name' => 'Desenvolvimento', 'active' => true]);
        $user = $this->user('Editor');
        $this->associate($user, $department, 'edit');

        $this->actingAs($user)->post('/departamentos/'.$department->id.'/pastas', [
            'name' => 'Afialo',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $root = TicketFolder::where('department_id', $department->id)->where('name', 'Afialo')->firstOrFail();

        $this->actingAs($user)->post('/departamentos/'.$department->id.'/pastas', [
            'name' => 'Agenda',
            'parent_id' => $root->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_folders', [
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => 'Agenda',
        ]);
    }

    public function test_parent_folder_from_another_department_is_rejected(): void
    {
        $a = Department::create(['name' => 'A', 'active' => true]);
        $b = Department::create(['name' => 'B', 'active' => true]);
        $user = $this->user('Editor cruzado');
        $this->associate($user, $a, 'edit');
        $foreign = TicketFolder::create(['department_id' => $b->id, 'name' => 'Outra']);

        $this->actingAs($user)->post('/departamentos/'.$a->id.'/pastas', [
            'name' => 'Inválida',
            'parent_id' => $foreign->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('ticket_folders', [
            'department_id' => $a->id,
            'name' => 'Inválida',
        ]);
    }

    public function test_triage_send_and_view_only_users_cannot_create_folders(): void
    {
        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $send = $this->user('Somente envia');
        $view = $this->user('Somente visualiza');
        $this->associate($send, $department, 'send');
        $this->associate($view, $department, 'view');

        $this->actingAs($send)->post('/departamentos/'.$department->id.'/pastas', ['name' => 'Pasta'])
            ->assertForbidden();
        $this->actingAs($view)->post('/departamentos/'.$department->id.'/pastas', ['name' => 'Pasta'])
            ->assertForbidden();

        $admin = $this->user('Administrador', 'Super Admin');
        $triage = Department::triage();
        $this->actingAs($admin)->post('/departamentos/'.$triage->id.'/pastas', ['name' => 'Não pode'])
            ->assertForbidden();
    }

    public function test_global_manager_can_create_folder_without_membership(): void
    {
        $admin = $this->user('Administrador global', 'Super Admin');
        $department = Department::create(['name' => 'Projetos', 'active' => true]);

        $this->actingAs($admin)->post('/departamentos/'.$department->id.'/pastas', [
            'name' => 'Produto',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_folders', [
            'department_id' => $department->id,
            'name' => 'Produto',
        ]);
        $this->assertDatabaseMissing('department_user_access', [
            'user_id' => $admin->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_tree_service_returns_root_tickets_and_recursive_folders_in_operational_order(): void
    {
        $department = Department::create(['name' => 'Árvore', 'active' => true]);
        $root = TicketFolder::create(['department_id' => $department->id, 'name' => 'Projeto']);
        $child = TicketFolder::create(['department_id' => $department->id, 'parent_id' => $root->id, 'name' => 'Bugs']);
        $status = Status::system('new');

        $rootTicket = Ticket::create([
            'number' => Ticket::nextNumber(), 'origin' => 'internal', 'title' => 'Raiz', 'description' => 'Teste',
            'priority' => 'normal', 'status_id' => $status->id, 'department_id' => $department->id,
            'due_at' => now()->addDays(3),
        ]);
        $urgent = Ticket::create([
            'number' => Ticket::nextNumber(), 'origin' => 'internal', 'title' => 'Urgente', 'description' => 'Teste',
            'priority' => 'urgent', 'status_id' => $status->id, 'department_id' => $department->id,
            'folder_id' => $child->id, 'due_at' => now()->addDay(),
        ]);

        $tree = app(TicketFolderTree::class)->forDepartment($department);

        $this->assertSame(2, $tree['ticket_count']);
        $this->assertSame($rootTicket->id, $tree['root_tickets']->first()->id);
        $this->assertSame($root->id, $tree['folders'][0]['folder']->id);
        $this->assertSame($child->id, $tree['folders'][0]['children'][0]['folder']->id);
        $this->assertSame($urgent->id, $tree['folders'][0]['children'][0]['tickets']->first()->id);
    }
}
