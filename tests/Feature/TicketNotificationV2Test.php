<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketNotificationV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, array $permissions = []): User
    {
        $role = Role::create(['name' => $name.' '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function access(User $user, Department $department, string $level, bool $follow = false): void
    {
        $user->departments()->syncWithoutDetaching([
            $department->id => ['access_level' => $level, 'follow_department' => $follow],
        ]);
    }

    private function ticket(Department $department, ?User $requester = null, ?User $assignee = null): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket de notificação',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'department_id' => $department->id,
            'requester_user_id' => $requester?->id,
            'requester_name' => $requester?->name,
            'requester_email' => $requester?->email,
            'assignee_id' => $assignee?->id,
            'due_at' => now()->addDay(),
        ]);
    }

    public function test_opening_notifies_requester_and_department_follower_but_not_creator_only_for_creating(): void
    {
        Notification::fake();
        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $creator = $this->user('Criador', ['tickets.create']);
        $requester = $this->user('Solicitante');
        $departmentFollower = $this->user('Acompanhante');
        $this->access($creator, $department, 'send');
        $this->access($departmentFollower, $department, 'view', true);

        $this->actingAs($creator)->post('/tickets', [
            'title' => 'Novo chamado',
            'description' => 'Detalhes',
            'priority' => 'normal',
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'source_mode' => 'internal',
            'department_id' => $department->id,
            'requester_user_id' => $requester->id,
        ])->assertRedirect();

        Notification::assertNotSentTo($creator, TicketActivityNotification::class);
        Notification::assertSentTo($requester, TicketActivityNotification::class, function ($notification) {
            return $notification->eventKey === 'ticket.opened' && $notification->mandatoryMail === true;
        });
        Notification::assertSentTo($departmentFollower, TicketActivityNotification::class);
    }

    public function test_internal_notification_is_always_stored_and_optional_email_respects_preference(): void
    {
        $department = Department::create(['name' => 'Preferências', 'active' => true]);
        $user = $this->user('Preferências');
        $ticket = $this->ticket($department, $user);

        NotificationPreference::create([
            'user_id' => $user->id,
            'event_key' => 'ticket.comment.public',
            'email_enabled' => false,
        ]);

        $optional = new TicketActivityNotification(
            $ticket,
            'Novo comentário',
            'Há uma atualização.',
            null,
            'ticket.comment.public',
            false,
        );

        $this->assertSame(['database'], $optional->via($user));

        $mandatory = new TicketActivityNotification(
            $ticket,
            'Ticket criado',
            'Seu ticket foi registrado.',
            null,
            'ticket.opened',
            true,
        );

        $this->assertSame(['database', 'mail'], $mandatory->via($user));
    }

    public function test_public_comment_notifies_only_checked_groups_and_internal_note_never_notifies_requester(): void
    {
        Notification::fake();
        $department = Department::create(['name' => 'Atendimento', 'active' => true]);
        $actor = $this->user('Atendente', ['tickets.comment', 'tickets.internal_note']);
        $requester = $this->user('Cliente Interno');
        $assignee = $this->user('Responsável');
        $collaborator = $this->user('Colaborador');
        $follower = $this->user('Seguidor');
        $this->access($actor, $department, 'view');
        $ticket = $this->ticket($department, $requester, $assignee);
        $ticket->participants()->attach([
            $collaborator->id => ['type' => 'collaborator', 'notify_status' => true, 'notify_comments' => true, 'notify_attachments' => true],
            $follower->id => ['type' => 'follower', 'notify_status' => true, 'notify_comments' => true, 'notify_attachments' => true],
        ]);

        $this->actingAs($actor)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Atualização pública',
            'notify_requester' => 1,
            'notify_responsible' => 1,
            'notify_collaborators' => 1,
            'notify_followers' => 0,
        ])->assertRedirect();

        Notification::assertSentTo($requester, TicketActivityNotification::class);
        Notification::assertSentTo($assignee, TicketActivityNotification::class);
        Notification::assertSentTo($collaborator, TicketActivityNotification::class);
        Notification::assertNotSentTo($follower, TicketActivityNotification::class);

        Notification::fake();
        $this->actingAs($actor)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'internal',
            'body' => 'Nota que não pode sair para o solicitante',
            'notify_requester' => 1,
            'notify_responsible' => 1,
        ])->assertRedirect();
        Notification::assertNothingSent();
    }

    public function test_reassign_notifies_removed_and_added_responsible_but_not_actor(): void
    {
        Notification::fake();
        $department = Department::create(['name' => 'Operações', 'active' => true]);
        $actor = $this->user('Gestor', ['tickets.reassign']);
        $old = $this->user('Responsável Antigo');
        $new = $this->user('Responsável Novo');
        $this->access($actor, $department, 'edit');
        $ticket = $this->ticket($department, null, $old);

        $this->actingAs($actor)->patch('/tickets/'.$ticket->id.'/responsavel', [
            'user_id' => $new->id,
        ])->assertRedirect();

        Notification::assertSentTo($old, TicketActivityNotification::class);
        Notification::assertSentTo($new, TicketActivityNotification::class);
        Notification::assertNotSentTo($actor, TicketActivityNotification::class);
    }

    public function test_requester_notification_on_ticket_change_is_checked_by_default_and_can_be_disabled(): void
    {
        Notification::fake();
        $department = Department::create(['name' => 'Produto', 'active' => true]);
        $actor = $this->user('Editor', ['tickets.edit', 'tickets.change_priority', 'tickets.change_due_date', 'tickets.change_status']);
        $requester = $this->user('Solicitante Mudança');
        $this->access($actor, $department, 'edit');
        $ticket = $this->ticket($department, $requester);

        $this->actingAs($actor)->patch('/tickets/'.$ticket->id, [
            'title' => 'Título alterado',
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status_id' => $ticket->status_id,
            'due_at' => $ticket->due_at?->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        Notification::assertSentTo($requester, TicketActivityNotification::class);

        Notification::fake();
        $ticket->refresh();
        $this->actingAs($actor)->patch('/tickets/'.$ticket->id, [
            'title' => 'Título alterado novamente',
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status_id' => $ticket->status_id,
            'due_at' => $ticket->due_at?->format('Y-m-d H:i:s'),
            'notify_requester' => 0,
        ])->assertRedirect();

        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
    }

    public function test_closing_ticket_always_notifies_requester_even_when_checkbox_is_off(): void
    {
        Notification::fake();
        $department = Department::create(['name' => 'Encerramento', 'active' => true]);
        $actor = $this->user('Fechador', ['tickets.close']);
        $requester = $this->user('Solicitante Encerramento');
        $this->access($actor, $department, 'edit');
        $ticket = $this->ticket($department, $requester);
        $ticket->update(['status_id' => Status::system('resolved')->id]);

        $this->actingAs($actor)->post('/tickets/'.$ticket->id.'/fechar', [
            'notify_requester' => 0,
        ])->assertRedirect();

        Notification::assertSentTo($requester, TicketActivityNotification::class, function ($notification) {
            return $notification->eventKey === 'ticket.closed' && $notification->mandatoryMail === true;
        });
    }
}
