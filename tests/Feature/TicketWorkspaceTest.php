<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(Department $department, array $permissions): User
    {
        $role = Role::create(['name' => 'Workspace '.uniqid()]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'group' => 'tickets']);
            $role->permissions()->attach($permission->id);
        }
        return User::create([
            'name' => 'Usuário', 'email' => uniqid('u').'@sutoorii.com', 'email_verified_at' => now(),
            'password' => 'SenhaTeste123', 'role_id' => $role->id, 'department_id' => $department->id, 'active' => true,
        ]);
    }

    private function status(string $key, string $name, string $category = 'open'): Status
    {
        return Status::firstOrCreate(['system_key' => $key], ['name' => $name, 'category' => $category, 'color' => '#6D28D9', 'active' => true]);
    }

    public function test_authorized_user_can_edit_ticket_fields(): void
    {
        $department = Department::create(['name' => 'Suporte']);
        $user = $this->user($department, ['tickets.view_department','tickets.edit','tickets.change_priority','tickets.change_due_date','tickets.change_status']);
        $new = $this->status('new','Novo');
        $progress = $this->status('in_progress','Em andamento');
        $ticket = Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Antigo','description'=>'Texto antigo','priority'=>'normal','status_id'=>$new->id,'department_id'=>$department->id,'due_at'=>now()->addDay()]);

        $this->actingAs($user)->patch('/tickets/'.$ticket->id, [
            'title'=>'Novo título','description'=>'Nova descrição','priority'=>'high','status_id'=>$progress->id,'due_at'=>now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame('Novo título', $ticket->title);
        $this->assertSame('Nova descrição', $ticket->description);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame($progress->id, $ticket->status_id);
        $this->assertDatabaseHas('ticket_events', ['ticket_id'=>$ticket->id,'event'=>'ticket.updated']);
    }

    public function test_public_comment_and_internal_note_require_their_permissions(): void
    {
        $department = Department::create(['name' => 'Atendimento']);
        $status = $this->status('new','Novo');
        $user = $this->user($department, ['tickets.view_department','tickets.comment','tickets.internal_note']);
        $ticket = Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Ticket','description'=>'Descrição','priority'=>'normal','status_id'=>$status->id,'department_id'=>$department->id]);

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/comentarios', ['visibility'=>'public','body'=>'Resposta ao solicitante'])->assertRedirect();
        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/comentarios', ['visibility'=>'internal','body'=>'Somente equipe'])->assertRedirect();

        $this->assertDatabaseHas('comments', ['ticket_id'=>$ticket->id,'visibility'=>'public','body'=>'Resposta ao solicitante']);
        $this->assertDatabaseHas('comments', ['ticket_id'=>$ticket->id,'visibility'=>'internal','body'=>'Somente equipe']);
    }
}
