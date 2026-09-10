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
use Tests\TestCase;

class TicketAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(DatabaseSeeder::class); }

    private function user(Department $department, array $permissions): User
    {
        $role = Role::create(['name'=>'R '.uniqid(), 'active'=>true]);
        $role->permissions()->sync(Permission::whereIn('key',$permissions)->pluck('id'));
        return User::create(['name'=>'Usuário','email'=>uniqid().'@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$role->id,'department_id'=>$department->id,'active'=>true]);
    }

    private function ticket(Department $department, string $status='new', ?User $assignee=null): Ticket
    {
        return Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Teste','description'=>'Descrição','priority'=>'normal','status_id'=>Status::system($status)->id,'department_id'=>$department->id,'assignee_id'=>$assignee?->id]);
    }

    public function test_member_can_assume_unassigned_ticket_and_status_becomes_in_progress(): void
    {
        $department = Department::create(['name'=>'Suporte']);
        $user = $this->user($department,['tickets.assume','tickets.view_department']);
        $ticket = $this->ticket($department,'forwarded');

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/assumir')->assertRedirect();

        $ticket->refresh();
        $this->assertSame($user->id,$ticket->assignee_id);
        $this->assertSame('in_progress',$ticket->status->system_key);
        $this->assertDatabaseHas('ticket_events',['ticket_id'=>$ticket->id,'event'=>'assumed','actor_id'=>$user->id]);
    }

    public function test_user_from_another_department_cannot_assume_ticket(): void
    {
        $a=Department::create(['name'=>'A']); $b=Department::create(['name'=>'B']);
        $user=$this->user($a,['tickets.assume']); $ticket=$this->ticket($b);
        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/assumir')->assertForbidden();
        $this->assertNull($ticket->fresh()->assignee_id);
    }

    public function test_reassignment_requires_specific_permission(): void
    {
        $department=Department::create(['name'=>'Atendimento']);
        $current=$this->user($department,[]); $target=$this->user($department,[]);
        $without=$this->user($department,['tickets.view_department']);
        $ticket=$this->ticket($department,'in_progress',$current);

        $this->actingAs($without)->patch('/tickets/'.$ticket->id.'/responsavel',['user_id'=>$target->id])->assertForbidden();

        $manager=$this->user($department,['tickets.reassign']);
        $this->actingAs($manager)->patch('/tickets/'.$ticket->id.'/responsavel',['user_id'=>$target->id])->assertRedirect();
        $this->assertSame($target->id,$ticket->fresh()->assignee_id);
        $this->assertDatabaseHas('ticket_events',['ticket_id'=>$ticket->id,'event'=>'reassigned','actor_id'=>$manager->id]);
    }
}
