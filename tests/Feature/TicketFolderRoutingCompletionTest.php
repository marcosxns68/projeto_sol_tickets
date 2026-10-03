<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentSubscription;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketFolder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketFolderRoutingCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(array $permissions = []): User
    {
        $role = Role::create(['name' => 'Pastas '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return User::create([
            'name' => 'Usuário Pastas',
            'email' => uniqid('folders-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function access(User $user, Department $department, string $level): void
    {
        DB::table('department_user_access')->insert([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => $level,
            'follow_department' => false,
            'notify_email' => false,
            'notify_whatsapp' => false,
            'notify_push' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ticket(Department $department, ?TicketFolder $folder = null): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket de teste '.uniqid(),
            'description' => 'Descrição do ticket',
            'priority' => 'normal',
            'status_id' => Status::system('in_progress')->id,
            'department_id' => $department->id,
            'folder_id' => $folder?->id,
        ]);
    }

    public function test_contextual_creation_stores_selected_folder(): void
    {
        $user = $this->user(['tickets.create']);
        $department = Department::create(['name' => 'Desenvolvimento '.uniqid(), 'active' => true]);
        $folder = TicketFolder::create(['department_id' => $department->id, 'name' => 'Afialo']);
        $this->access($user, $department, 'edit');

        $this->actingAs($user)->post('/tickets', [
            'source_mode' => 'internal',
            'title' => 'Criado dentro da pasta',
            'description' => 'Deve nascer dentro de Afialo',
            'priority' => 'normal',
            'department_id' => $department->id,
            'folder_id' => $folder->id,
        ])->assertRedirect();

        $this->assertSame($folder->id, Ticket::latest('id')->firstOrFail()->folder_id);
    }

    public function test_contextual_creation_rejects_folder_from_another_department(): void
    {
        $user = $this->user(['tickets.create']);
        $department = Department::create(['name' => 'Destino '.uniqid(), 'active' => true]);
        $other = Department::create(['name' => 'Outro '.uniqid(), 'active' => true]);
        $foreignFolder = TicketFolder::create(['department_id' => $other->id, 'name' => 'Pasta de outro departamento']);
        $this->access($user, $department, 'edit');

        $this->actingAs($user)->post('/tickets', [
            'source_mode' => 'internal',
            'title' => 'Pasta inválida',
            'description' => 'Não pode ser criado',
            'priority' => 'normal',
            'department_id' => $department->id,
            'folder_id' => $foreignFolder->id,
        ])->assertSessionHasErrors('folder_id');

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_folder_only_move_keeps_department_status_and_does_not_forward(): void
    {
        $user = $this->user([]);
        $department = Department::create(['name' => 'Produto '.uniqid(), 'active' => true]);
        $first = TicketFolder::create(['department_id' => $department->id, 'name' => 'Backlog']);
        $second = TicketFolder::create(['department_id' => $department->id, 'name' => 'Em execução']);
        $this->access($user, $department, 'edit');
        $ticket = $this->ticket($department, $first);
        $statusId = $ticket->status_id;

        $this->actingAs($user)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $department->id,
            'folder_id' => $second->id,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($department->id, $ticket->department_id);
        $this->assertSame($second->id, $ticket->folder_id);
        $this->assertSame($statusId, $ticket->status_id);
        $this->assertDatabaseMissing('ticket_events', ['ticket_id' => $ticket->id, 'event' => 'forwarded']);
    }

    public function test_cross_department_move_clears_old_folder_when_target_folder_is_not_supplied(): void
    {
        $user = $this->user(['tickets.forward']);
        $source = Department::create(['name' => 'Origem '.uniqid(), 'active' => true]);
        $target = Department::create(['name' => 'Destino '.uniqid(), 'active' => true]);
        $oldFolder = TicketFolder::create(['department_id' => $source->id, 'name' => 'Pasta antiga']);
        $this->access($user, $source, 'edit');
        $this->access($user, $target, 'send');
        $ticket = $this->ticket($source, $oldFolder);

        $this->actingAs($user)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $target->id,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($target->id, $ticket->department_id);
        $this->assertNull($ticket->folder_id);
        $this->assertSame('forwarded', $ticket->status->system_key);
    }

    public function test_cross_department_move_accepts_folder_only_when_target_tree_is_visible(): void
    {
        $user = $this->user(['tickets.forward']);
        $source = Department::create(['name' => 'Origem válida '.uniqid(), 'active' => true]);
        $target = Department::create(['name' => 'Destino válido '.uniqid(), 'active' => true]);
        $oldFolder = TicketFolder::create(['department_id' => $source->id, 'name' => 'Origem']);
        $targetFolder = TicketFolder::create(['department_id' => $target->id, 'name' => 'Destino']);
        $this->access($user, $source, 'edit');
        $this->access($user, $target, 'view');
        $ticket = $this->ticket($source, $oldFolder);

        $this->actingAs($user)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $target->id,
            'folder_id' => $targetFolder->id,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($target->id, $ticket->department_id);
        $this->assertSame($targetFolder->id, $ticket->folder_id);
    }

    public function test_send_only_target_cannot_force_folder_but_can_forward_to_root(): void
    {
        $user = $this->user(['tickets.forward']);
        $source = Department::create(['name' => 'Origem envio '.uniqid(), 'active' => true]);
        $target = Department::create(['name' => 'Destino envio '.uniqid(), 'active' => true]);
        $targetFolder = TicketFolder::create(['department_id' => $target->id, 'name' => 'Interna']);
        $this->access($user, $source, 'edit');
        $this->access($user, $target, 'send');
        $ticket = $this->ticket($source);

        $this->actingAs($user)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $target->id,
            'folder_id' => $targetFolder->id,
        ])->assertForbidden();

        $ticket->refresh();
        $this->assertSame($source->id, $ticket->department_id);

        $this->actingAs($user)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $target->id,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($target->id, $ticket->department_id);
        $this->assertNull($ticket->folder_id);
    }

    public function test_invalid_folder_does_not_partially_move_ticket(): void
    {
        $user = $this->user(['tickets.forward']);
        $source = Department::create(['name' => 'Origem protegida '.uniqid(), 'active' => true]);
        $target = Department::create(['name' => 'Destino protegido '.uniqid(), 'active' => true]);
        $third = Department::create(['name' => 'Terceiro '.uniqid(), 'active' => true]);
        $oldFolder = TicketFolder::create(['department_id' => $source->id, 'name' => 'Pasta atual']);
        $invalidFolder = TicketFolder::create(['department_id' => $third->id, 'name' => 'Pasta inválida']);
        $this->access($user, $source, 'edit');
        $this->access($user, $target, 'view');
        $ticket = $this->ticket($source, $oldFolder);

        $this->actingAs($user)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $target->id,
            'folder_id' => $invalidFolder->id,
        ])->assertSessionHasErrors('folder_id');

        $ticket->refresh();
        $this->assertSame($source->id, $ticket->department_id);
        $this->assertSame($oldFolder->id, $ticket->folder_id);
    }

    public function test_global_subscriber_novidades_filter_uses_new_subscription_last_seen(): void
    {
        $user = $this->user(['tickets.view_all']);
        $department = Department::create(['name' => 'Caixa acompanhada '.uniqid(), 'active' => true]);
        DepartmentSubscription::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'notify_email' => true,
            'notify_whatsapp' => false,
            'notify_push' => false,
            'last_seen_at' => now()->subHour(),
        ]);

        $old = $this->ticket($department);
        $old->forceFill(['title' => 'Ticket anterior', 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->saveQuietly();
        $new = $this->ticket($department);
        $new->forceFill(['title' => 'Ticket novo', 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)])->saveQuietly();

        $this->actingAs($user)->get('/departamentos/'.$department->id.'/tickets?novos=1')
            ->assertOk()
            ->assertSee('Ticket novo')
            ->assertDontSee('Ticket anterior');
    }

    public function test_folder_options_endpoint_returns_nested_paths_and_current_folder(): void
    {
        $user = $this->user(['tickets.view_all']);
        $department = Department::create(['name' => 'Estrutura '.uniqid(), 'active' => true]);
        $root = TicketFolder::create(['department_id' => $department->id, 'name' => 'Cliente']);
        $child = TicketFolder::create(['department_id' => $department->id, 'parent_id' => $root->id, 'name' => 'Bugs']);
        $ticket = $this->ticket($department, $child);

        $this->actingAs($user)->getJson('/departamentos/'.$department->id.'/pastas')
            ->assertOk()
            ->assertJsonFragment(['id' => $child->id, 'path' => 'Cliente / Bugs']);

        $this->actingAs($user)->getJson('/tickets/'.$ticket->id.'/pastas')
            ->assertOk()
            ->assertJsonPath('department_id', $department->id)
            ->assertJsonPath('folder_id', $child->id);
    }

    public function test_send_only_user_cannot_enumerate_department_folders(): void
    {
        $user = $this->user(['tickets.create']);
        $department = Department::create(['name' => 'Privado '.uniqid(), 'active' => true]);
        TicketFolder::create(['department_id' => $department->id, 'name' => 'Segredo']);
        $this->access($user, $department, 'send');

        $this->actingAs($user)->getJson('/departamentos/'.$department->id.'/pastas')->assertForbidden();
    }

    public function test_folder_routing_script_is_loaded_in_authenticated_layout(): void
    {
        $user = $this->user(['tickets.create', 'tickets.view_all']);
        $department = Department::create(['name' => 'Interface '.uniqid(), 'active' => true]);
        $ticket = $this->ticket($department);

        $this->actingAs($user)->get('/tickets/create')
            ->assertOk()
            ->assertSee('ticket-folder-routing.js', false);

        $this->actingAs($user)->get('/tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('ticket-folder-routing.js', false);
    }
}
