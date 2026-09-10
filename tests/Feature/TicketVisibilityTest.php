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

class TicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, Department $department, array $permissions = []): User
    {
        $role = Role::create(['name' => $name.' '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return User::create([
            'name' => $name,
            'email' => uniqid(strtolower($name)).'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'department_id' => $department->id,
            'active' => true,
        ]);
    }

    private function ticket(Department $department, ?User $creator = null): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket de teste',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'creator_id' => $creator?->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_creator_alone_loses_visibility_when_ticket_is_in_another_department(): void
    {
        $origin = Department::create(['name' => 'Origem']);
        $destination = Department::create(['name' => 'Destino']);
        $creator = $this->user('Criador', $origin, ['tickets.view_department']);
        $ticket = $this->ticket($destination, $creator);

        $this->actingAs($creator)->get(route('tickets.show', $ticket))->assertForbidden();
    }

    public function test_department_member_with_permission_can_view_ticket(): void
    {
        $department = Department::create(['name' => 'Suporte']);
        $member = $this->user('Membro', $department, ['tickets.view_department']);
        $ticket = $this->ticket($department);

        $this->actingAs($member)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_collaborator_and_follower_keep_visibility_outside_their_department(): void
    {
        $origin = Department::create(['name' => 'Origem']);
        $destination = Department::create(['name' => 'Destino']);
        $collaborator = $this->user('Colaborador', $origin);
        $follower = $this->user('Seguidor', $origin);
        $ticket = $this->ticket($destination);

        $ticket->participants()->attach($collaborator->id, ['type' => 'collaborator']);
        $ticket->participants()->attach($follower->id, ['type' => 'follower']);

        $this->actingAs($collaborator)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($follower)->get(route('tickets.show', $ticket))->assertOk();
    }
}
