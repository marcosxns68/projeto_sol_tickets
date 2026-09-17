<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $role = Role::create(['name' => 'Usuário Notificações '.uniqid(), 'active' => true]);

        return User::create([
            'name' => 'Pessoa Notificada',
            'email' => uniqid('notify-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function ticket(User $requester): Ticket
    {
        $department = Department::create(['name' => 'Central '.uniqid(), 'active' => true]);
        $status = Status::firstOrCreate(
            ['system_key' => 'new'],
            ['name' => 'Novo', 'category' => 'open', 'color' => '#6D28D9', 'active' => true]
        );

        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket da central',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => $status->id,
            'department_id' => $department->id,
            'requester_user_id' => $requester->id,
            'requester_name' => $requester->name,
            'requester_email' => $requester->email,
        ]);
    }

    public function test_internal_ticket_notification_is_persisted_and_visible_in_notification_center(): void
    {
        Mail::fake();
        $user = $this->user();
        $ticket = $this->ticket($user);

        $user->notify(new TicketActivityNotification(
            $ticket,
            'Ticket atualizado',
            'Houve uma alteração no ticket.',
            route('tickets.show', $ticket),
            'ticket.updated',
            false,
        ));

        $this->assertSame(1, $user->notifications()->count());

        $this->actingAs($user)
            ->get('/notificacoes')
            ->assertOk()
            ->assertSee('Notificações')
            ->assertSee('Ticket atualizado')
            ->assertSee('Preferências de e-mail');
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        Mail::fake();
        $user = $this->user();
        $ticket = $this->ticket($user);
        $user->notify(new TicketActivityNotification(
            $ticket,
            'Novo comentário',
            'Há um comentário público.',
            route('tickets.show', $ticket),
            'ticket.comment.public',
            false,
        ));

        $notification = $user->notifications()->firstOrFail();
        $this->assertNull($notification->read_at);

        $this->actingAs($user)
            ->patch('/notificacoes/'.$notification->id.'/lida')
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_user_can_disable_optional_email_events_without_disabling_internal_notifications(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patch('/notificacoes/preferencias', [
                'email_events' => ['ticket.assignment.changed'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'event_key' => 'ticket.comment.public',
            'email_enabled' => 0,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'event_key' => 'ticket.assignment.changed',
            'email_enabled' => 1,
        ]);

        $this->assertFalse(NotificationPreference::emailEnabled($user, 'ticket.comment.public'));
        $this->assertTrue(NotificationPreference::emailEnabled($user, 'ticket.assignment.changed'));
    }
}
