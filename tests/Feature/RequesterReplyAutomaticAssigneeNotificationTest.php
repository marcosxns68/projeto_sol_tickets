<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RequesterReplyAutomaticAssigneeNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('resposta-').'@sutoorii.test',
            'password' => 'SenhaTeste123!',
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function ticket(User $requester, User $assignee): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Resposta do cliente',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'requester_user_id' => $requester->id,
            'requester_name' => $requester->name,
            'requester_email' => $requester->email,
            'assignee_id' => $assignee->id,
        ]);
    }

    public function test_internal_requester_reply_notifies_assignee_without_any_notification_checkbox(): void
    {
        Notification::fake();
        $requester = $this->user('Solicitante interno');
        $assignee = $this->user('Responsável');

        $ticket = $this->ticket($requester, $assignee);
        $this->actingAs($requester)
            ->post('/tickets/'.$ticket->id.'/comentarios', [
                'visibility' => 'public',
                'body' => 'Cliente acrescentou informação sem marcar avisos.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($assignee, TicketActivityNotification::class, function ($notification) {
            return $notification->event === 'ticket.comment.public'
                && $notification->via($this->user('Conta teste não envolvida')) === ['database', 'mail'];
        });
        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
    }

    public function test_combined_ticket_save_notifies_assignee_for_requester_public_comment_without_checkbox(): void
    {
        Notification::fake();
        $requester = $this->user('Solicitante interno');
        $assignee = $this->user('Responsável');

        $ticket = $this->ticket($requester, $assignee);
        $this->actingAs($requester)
            ->patch('/tickets/'.$ticket->id, [
                'title' => $ticket->title,
                'description' => $ticket->description,
                'priority' => $ticket->priority,
                'status_id' => $ticket->status_id,
                'due_at' => null,
                'comment_body' => 'Nova informação do cliente.',
                'comment_visibility' => 'public',
                'comment_notify_requester' => '0',
                'notify_responsible' => '0',
            ])
            ->assertRedirect();

        Notification::assertSentTo($assignee, TicketActivityNotification::class, 1);
        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
        $this->assertDatabaseHas('comments', [
            'ticket_id' => $ticket->id,
            'body' => 'Nova informação do cliente.',
            'visibility' => 'public',
        ]);
    }

    public function test_integrated_requester_reply_persists_bell_notification_for_responsible_with_email_transport_mocked(): void
    {
        // O e-mail não precisa sair pela rede para que o aviso no sininho seja
        // registrado. A entrega SMTP real é verificada separadamente em produção.
        config(['mail.default' => 'array']);
        $assignee = $this->user('Responsável integração');
        $company = Company::create(['name' => 'Empresa de teste', 'active' => false]);
        $system = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Estúdio França',
            'active' => true,
        ]);
        $system->forceFill(['api_token_hash' => hash('sha256', 'token-teste-resposta')])->save();

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => 'Ticket integrado',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'system_id' => $system->id,
            'external_requester_id' => 'estudio-franca-153',
            'requester_name' => 'Cliente externo',
            'requester_email' => 'cliente@example.test',
            'assignee_id' => $assignee->id,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer token-teste-resposta',
            'X-External-User-Id' => 'estudio-franca-153',
            'X-External-User-Role' => 'user',
        ])->postJson('/api/v1/tickets/'.$ticket->number.'/comments', [
            'body' => 'Cliente respondeu pelo sistema integrado.',
            'external_message_id' => 'mensagem-uma',
        ])->assertCreated();

        $notification = $assignee->notifications()->firstOrFail();
        $this->assertSame('ticket.comment.public', $notification->data['event']);
        $this->assertSame($ticket->id, $notification->data['ticket_id']);
        $this->assertSame($ticket->number, $notification->data['ticket_number']);
        $this->assertSame(1, $assignee->unreadNotifications()->count());
    }
}
