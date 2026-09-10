<?php

namespace Tests\Feature;

use App\Models\ChecklistItem;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function user(Department $department, array $permissions): User
    {
        $role = Role::create(['name' => 'Lifecycle '.uniqid()]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key'=>$key], ['name'=>$key,'group'=>'tickets']);
            $role->permissions()->attach($permission->id);
        }
        return User::create(['name'=>'Usuário','email'=>uniqid('life').'@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$role->id,'department_id'=>$department->id,'active'=>true]);
    }

    private function ticketStatus(string $key, string $name, string $category = 'open'): Status
    {
        return Status::firstOrCreate(['system_key'=>$key], ['name'=>$name,'category'=>$category,'color'=>'#6D28D9','active'=>true]);
    }

    public function test_checklist_can_be_created_and_toggled(): void
    {
        $department = Department::create(['name'=>'Operações']);
        $user = $this->user($department, ['tickets.view_department','tickets.manage_checklist']);
        $status = $this->ticketStatus('new','Novo');
        $ticket = Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Ticket','description'=>'Descrição','priority'=>'normal','status_id'=>$status->id,'department_id'=>$department->id]);

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/checklist', ['text'=>'Validar retorno','required'=>1])->assertRedirect();
        $item = ChecklistItem::where('ticket_id',$ticket->id)->firstOrFail();
        $this->actingAs($user)->patch('/tickets/'.$ticket->id.'/checklist/'.$item->id.'/alternar')->assertRedirect();

        $this->assertTrue((bool)$item->fresh()->completed);
        $this->assertDatabaseHas('ticket_events',['ticket_id'=>$ticket->id,'event'=>'checklist.toggled']);
    }

    public function test_only_assignee_resolves_and_required_checklist_blocks_resolution(): void
    {
        $department = Department::create(['name'=>'Desenvolvimento']);
        $assignee = $this->user($department, ['tickets.view_department','tickets.resolve','tickets.manage_checklist']);
        $other = $this->user($department, ['tickets.view_department','tickets.resolve']);
        $progress = $this->ticketStatus('in_progress','Em andamento');
        $resolved = $this->ticketStatus('resolved','Resolvido','completed');
        $ticket = Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Ticket','description'=>'Descrição','priority'=>'normal','status_id'=>$progress->id,'department_id'=>$department->id,'assignee_id'=>$assignee->id]);
        $item = ChecklistItem::create(['ticket_id'=>$ticket->id,'text'=>'Pendência','required'=>true]);

        $this->actingAs($other)->post('/tickets/'.$ticket->id.'/resolver')->assertForbidden();
        $this->actingAs($assignee)->post('/tickets/'.$ticket->id.'/resolver')->assertSessionHasErrors();

        $item->update(['completed'=>true,'completed_by'=>$assignee->id,'completed_at'=>now()]);
        $this->actingAs($assignee)->post('/tickets/'.$ticket->id.'/resolver')->assertRedirect();
        $this->assertSame($resolved->id,$ticket->fresh()->status_id);
        $this->assertNotNull($ticket->fresh()->completed_at);
    }

    public function test_close_cancel_and_reopen_follow_permissions(): void
    {
        $department = Department::create(['name'=>'Gestão']);
        $user = $this->user($department, ['tickets.view_department','tickets.close','tickets.cancel','tickets.reopen']);
        $progress = $this->ticketStatus('in_progress','Em andamento');
        $resolved = $this->ticketStatus('resolved','Resolvido','completed');
        $closed = $this->ticketStatus('closed','Fechado','completed');
        $cancelled = $this->ticketStatus('cancelled','Cancelado','cancelled');
        $ticket = Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Ticket','description'=>'Descrição','priority'=>'normal','status_id'=>$resolved->id,'department_id'=>$department->id]);

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/fechar')->assertRedirect();
        $this->assertSame($closed->id,$ticket->fresh()->status_id);
        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/reabrir')->assertRedirect();
        $this->assertSame($progress->id,$ticket->fresh()->status_id);
        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/cancelar')->assertRedirect();
        $this->assertSame($cancelled->id,$ticket->fresh()->status_id);
    }
}
