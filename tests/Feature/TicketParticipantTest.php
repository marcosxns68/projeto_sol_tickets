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

class TicketParticipantTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(DatabaseSeeder::class); }

    private function user(Department $department, array $permissions=[]): User
    {
        $role=Role::create(['name'=>'R '.uniqid(),'active'=>true]);
        $role->permissions()->sync(Permission::whereIn('key',$permissions)->pluck('id'));
        return User::create(['name'=>'Pessoa','email'=>uniqid().'@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$role->id,'department_id'=>$department->id,'active'=>true]);
    }

    public function test_authorized_user_can_add_and_remove_collaborator_and_follower(): void
    {
        $department=Department::create(['name'=>'Operações']);
        $actor=$this->user($department,['tickets.manage_participants','tickets.view_department']);
        $collaborator=$this->user($department); $follower=$this->user($department);
        $ticket=Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Ticket','description'=>'Descrição','priority'=>'normal','status_id'=>Status::system('new')->id,'department_id'=>$department->id]);

        $this->actingAs($actor)->post('/tickets/'.$ticket->id.'/participantes',['user_id'=>$collaborator->id,'type'=>'collaborator'])->assertRedirect();
        $this->actingAs($actor)->post('/tickets/'.$ticket->id.'/participantes',['user_id'=>$follower->id,'type'=>'follower'])->assertRedirect();

        $this->assertDatabaseHas('ticket_participants',['ticket_id'=>$ticket->id,'user_id'=>$collaborator->id,'type'=>'collaborator']);
        $this->assertDatabaseHas('ticket_participants',['ticket_id'=>$ticket->id,'user_id'=>$follower->id,'type'=>'follower']);
        $this->assertTrue(Ticket::query()->myBox($collaborator)->whereKey($ticket->id)->exists());
        $this->assertTrue(Ticket::query()->myBox($follower)->whereKey($ticket->id)->exists());

        $this->actingAs($actor)->delete('/tickets/'.$ticket->id.'/participantes/'.$collaborator->id)->assertRedirect();
        $this->assertDatabaseMissing('ticket_participants',['ticket_id'=>$ticket->id,'user_id'=>$collaborator->id]);
        $this->assertDatabaseHas('ticket_events',['ticket_id'=>$ticket->id,'event'=>'participant_removed']);
    }

    public function test_participant_management_requires_permission(): void
    {
        $department=Department::create(['name'=>'Financeiro']);
        $actor=$this->user($department,['tickets.view_department']); $other=$this->user($department);
        $ticket=Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Ticket','description'=>'Descrição','priority'=>'normal','status_id'=>Status::system('new')->id,'department_id'=>$department->id]);

        $this->actingAs($actor)->post('/tickets/'.$ticket->id.'/participantes',['user_id'=>$other->id,'type'=>'collaborator'])->assertForbidden();
    }
}
