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

class TicketBoxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(Department $department): User
    {
        $role = Role::create(['name' => 'Operador '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::whereIn('key', ['tickets.view_department'])->pluck('id'));

        return User::create([
            'name' => 'Operador',
            'email' => uniqid('operador').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'department_id' => $department->id,
            'active' => true,
        ]);
    }

    private function viewAllUser(): User
    {
        $role = Role::create(['name' => 'Gestor geral '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::where('key', 'tickets.view_all')->pluck('id'));

        return User::create([
            'name' => 'Gestor geral',
            'email' => uniqid('gestor').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'department_id' => null,
            'active' => true,
        ]);
    }

    private function ticket(Department $department, string $title, string $statusKey, ?User $assignee = null, ?User $creator = null): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => $title,
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system($statusKey)->id,
            'creator_id' => $creator?->id,
            'assignee_id' => $assignee?->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_my_box_contains_assignee_collaborator_and_follower_but_not_creator_only(): void
    {
        $department = Department::create(['name' => 'Suporte']);
        $user = $this->user($department);

        $assigned = $this->ticket($department, 'Sou responsável', 'new', $user);
        $collaborating = $this->ticket($department, 'Sou colaborador', 'new');
        $following = $this->ticket($department, 'Sou seguidor', 'new');
        $creatorOnly = $this->ticket($department, 'Apenas criei', 'new', null, $user);

        $collaborating->participants()->attach($user->id, ['type' => 'collaborator']);
        $following->participants()->attach($user->id, ['type' => 'follower']);

        $response = $this->actingAs($user)->get('/minha-caixa');
        $response->assertOk()
            ->assertSee($assigned->number)
            ->assertSee($collaborating->number)
            ->assertSee($following->number)
            ->assertDontSee($creatorOnly->number);
    }

    public function test_closed_resolved_and_cancelled_only_appear_when_filtered(): void
    {
        $department = Department::create(['name' => 'Atendimento']);
        $user = $this->user($department);
        $active = $this->ticket($department, 'Ativo', 'new', $user);
        $resolved = $this->ticket($department, 'Resolvido', 'resolved', $user);
        $closed = $this->ticket($department, 'Fechado', 'closed', $user);
        $cancelled = $this->ticket($department, 'Cancelado', 'cancelled', $user);

        $this->actingAs($user)->get('/minha-caixa')
            ->assertSee($active->number)
            ->assertDontSee($resolved->number)
            ->assertDontSee($closed->number)
            ->assertDontSee($cancelled->number);

        $this->actingAs($user)->get('/minha-caixa?status=closed')
            ->assertSee($closed->number)
            ->assertDontSee($active->number);
    }

    public function test_department_box_shows_department_tickets_to_its_members(): void
    {
        $department = Department::create(['name' => 'Desenvolvimento']);
        $user = $this->user($department);
        $ticket = $this->ticket($department, 'Fila do setor', 'new');

        $this->actingAs($user)
            ->get('/departamentos/'.$department->id.'/tickets')
            ->assertOk()
            ->assertSee($ticket->number);
    }

    public function test_view_all_user_has_a_general_box_for_unassigned_internal_and_integration_tickets(): void
    {
        $department = Department::create(['name' => 'Suporte geral']);
        $user = $this->viewAllUser();

        $internal = $this->ticket($department, 'Aberto pelo painel', 'new');
        $integration = $this->ticket($department, 'Aberto pelo Estúdio França', 'new');
        $integration->update(['origin' => 'integration']);

        $response = $this->actingAs($user)->get('/todos-os-tickets');

        $response->assertOk()
            ->assertSee($internal->number)
            ->assertSee($integration->number)
            ->assertSee('Todos os tickets');
    }

    public function test_user_without_view_all_cannot_open_general_box(): void
    {
        $department = Department::create(['name' => 'Suporte restrito']);
        $user = $this->user($department);

        $this->actingAs($user)->get('/todos-os-tickets')->assertForbidden();
    }
}
