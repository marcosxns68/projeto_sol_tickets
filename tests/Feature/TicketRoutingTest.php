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

class TicketRoutingTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(DatabaseSeeder::class); }

    private function user(Department $department, array $permissions): User
    {
        $role=Role::create(['name'=>'R '.uniqid(),'active'=>true]);
        $role->permissions()->sync(Permission::whereIn('key',$permissions)->pluck('id'));
        return User::create(['name'=>'Encaminhador','email'=>uniqid().'@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$role->id,'department_id'=>$department->id,'active'=>true]);
    }

    public function test_forwarding_changes_department_removes_assignee_and_sets_forwarded_status(): void
    {
        $source=Department::create(['name'=>'Suporte']); $target=Department::create(['name'=>'Desenvolvimento']);
        $actor=$this->user($source,['tickets.forward','tickets.view_department']);
        $assignee=$this->user($source,[]);
        $ticket=Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Erro','description'=>'Descrição','priority'=>'normal','status_id'=>Status::system('in_progress')->id,'creator_id'=>$actor->id,'assignee_id'=>$assignee->id,'department_id'=>$source->id]);

        $this->actingAs($actor)->post('/tickets/'.$ticket->id.'/encaminhar',['department_id'=>$target->id,'reason'=>'Precisa do desenvolvimento'])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($target->id,$ticket->department_id);
        $this->assertNull($ticket->assignee_id);
        $this->assertSame('forwarded',$ticket->status->system_key);
        $this->assertDatabaseHas('ticket_events',['ticket_id'=>$ticket->id,'event'=>'forwarded','actor_id'=>$actor->id]);
    }
}
