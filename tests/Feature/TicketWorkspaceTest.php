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

    private function ticketStatus(string $key, string $name, string $category = 'open'): Status
    {
        return Status::firstOrCreate(['system_key' => $key], ['name' => $name, 'category' => $category, 'color' => '#6D28D9', 'active' => true]);
    }

    public function test_authorized_user_can_edit_ticket_fields(): void
    {
        $department = Department::create(['name' => 'Suporte']);
        $user = $this->user($department, ['tickets.view_department','tickets.edit','tickets.change_priority','tickets.change_due_date','tickets.change_status']);
        $user->departments()->syncWithoutDetaching([
            $department->id => ['access_level' => 'edit', 'follow_department' => false],
        ]);
        $new = $this->ticketStatus('new','Novo');
        $progress = $this->ticketStatus('in_progress','Em andamento');
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
        $status = $this->ticketStatus('new','Novo');
        $user = $this->user($department, ['tickets.view_department','tickets.comment','tickets.internal_note']);
        $ticket = Ticket::create(['number'=>Ticket::nextNumber(),'origin'=>'internal','title'=>'Ticket','description'=>'Descrição','priority'=>'normal','status_id'=>$status->id,'department_id'=>$department->id]);

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/comentarios', ['visibility'=>'public','body'=>'Resposta ao solicitante'])->assertRedirect();
        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/comentarios', ['visibility'=>'internal','body'=>'Somente equipe'])->assertRedirect();

        $this->assertDatabaseHas('comments', ['ticket_id'=>$ticket->id,'visibility'=>'public','body'=>'Resposta ao solicitante']);
        $this->assertDatabaseHas('comments', ['ticket_id'=>$ticket->id,'visibility'=>'internal','body'=>'Somente equipe']);
    }

    public function test_ticket_workspace_prioritizes_conversation_and_collapses_secondary_tools(): void
    {
        $department = Department::create(['name' => 'Experiência mobile']);
        $status = $this->ticketStatus('in_progress', 'Em andamento', 'in_progress');
        $user = $this->user($department, [
            'tickets.view_department',
            'tickets.comment',
            'tickets.internal_note',
            'tickets.edit',
            'tickets.change_priority',
            'tickets.change_due_date',
            'tickets.change_status',
            'tickets.manage_checklist',
            'tickets.manage_attachments',
            'tickets.manage_participants',
            'tickets.manage_labels',
            'tickets.recurrence',
        ]);
        $user->departments()->syncWithoutDetaching([
            $department->id => ['access_level' => 'edit', 'follow_department' => false],
        ]);

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Tela de ticket mais simples',
            'description' => 'Descrição do chamado',
            'priority' => 'high',
            'status_id' => $status->id,
            'department_id' => $department->id,
            'assignee_id' => $user->id,
            'due_at' => now()->addDay(),
        ]);

        $ticket->comments()->create([
            'user_id' => $user->id,
            'visibility' => 'public',
            'body' => 'Primeiro comentário',
            'source' => 'web',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $ticket->comments()->create([
            'user_id' => $user->id,
            'visibility' => 'public',
            'body' => 'Último comentário',
            'source' => 'web',
        ]);

        $response = $this->actingAs($user)->get('/tickets/'.$ticket->id);

        $response->assertOk()
            ->assertSee('data-ticket-conversation', false)
            ->assertSee('data-ticket-comment-list', false)
            ->assertSee('data-ticket-composer', false)
            ->assertSeeInOrder([
                'data-ticket-conversation',
                'Primeiro comentário',
                'Último comentário',
                'data-ticket-composer',
            ], false)
            ->assertSee('class="ticket-summary-strip"', false)
            ->assertSee('data-ticket-tool="details"', false)
            ->assertSee('data-ticket-tool="checklist"', false)
            ->assertSee('data-ticket-tool="attachments"', false)
            ->assertSee('data-ticket-tool="recurrence"', false)
            ->assertSee('data-ticket-tool="participants"', false)
            ->assertSee('data-ticket-tool="history"', false)
            ->assertDontSee('<details class="ticket-disclosure" open', false);
    }


    public function test_history_shows_original_creation_date_time_and_source_for_external_and_internal_tickets(): void
    {
        $department = Department::create(['name' => 'Suporte histórico']);
        $status = $this->ticketStatus('new', 'Novo');
        $user = $this->user($department, ['tickets.view_department']);
        $integration = \App\Models\ConnectedSystem::create([
            'name' => 'Estúdio França',
            'company_id' => null,
            'active' => true,
            'api_key_hash' => hash('sha256', 'historico-test-key'),
        ]);
        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => 'Ticket externo',
            'description' => 'Problema informado',
            'priority' => 'normal',
            'status_id' => $status->id,
            'department_id' => $department->id,
            'system_id' => $integration->id,
            'created_at' => \Illuminate\Support\Carbon::parse('2026-09-17 14:35:00', 'America/Sao_Paulo'),
        ]);

        $this->actingAs($user)->get('/tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('Ticket criado em 17/09/2026 às 14:35')
            ->assertSee('Origem: integração Estúdio França');
    }

}
