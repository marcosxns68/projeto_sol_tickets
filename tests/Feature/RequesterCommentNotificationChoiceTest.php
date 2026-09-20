<?php

namespace Tests\Feature;

use App\Jobs\SendTicketWhatsAppAutomation;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Services\TicketWhatsAppAutomations;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RequesterCommentNotificationChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name = 'Atendente'): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('comentario-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => Role::where('name', 'Super Admin')->value('id'),
            'active' => true,
        ]);
    }

    private function ticket(User $requester, bool $withWhatsapp = true): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Teste de comentário',
            'description' => 'Mensagem inicial',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'requester_user_id' => $requester->id,
            'requester_name' => $requester->name,
            'requester_email' => $requester->email,
            'requester_whatsapp' => $withWhatsapp ? '5515999998888' : null,
        ]);
    }

    public function test_ticket_page_exposes_one_requester_checkbox_for_both_channels(): void
    {
        $operator = $this->user();
        $ticket = $this->ticket($this->user('Solicitante'));
        $page = $this->actingAs($operator)->get('/tickets/'.$ticket->id)->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('id="commentRequesterNotify"', $html);
        $this->assertStringContainsString('Notificar solicitante', $html);
        $this->assertStringContainsString('name="comment_notify_requester"', $html);
        $this->assertStringNotContainsString('Enviar e-mail para</span>', $html);
        $this->assertStringNotContainsString('Notificar equipe por e-mail', $html);
    }

    public function test_checked_comment_sends_requester_email_and_queues_whatsapp_when_enabled(): void
    {
        Notification::fake();
        Bus::fake();
        $operator = $this->user();
        $requester = $this->user('Solicitante');
        $ticket = $this->ticket($requester);
        app(TicketWhatsAppAutomations::class)->save('comment', true, 'Resposta ao ticket {numero}');

        $this->actingAs($operator)->patch('/tickets/'.$ticket->id, [
            'title' => $ticket->title,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status_id' => $ticket->status_id,
            'due_at' => null,
            'comment_body' => 'O problema foi resolvido.',
            'comment_visibility' => 'public',
            'comment_notify_requester' => '1',
        ])->assertRedirect();

        Notification::assertSentTo($requester, TicketActivityNotification::class, 1);
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, 1);
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, fn ($job) => $job->event === 'comment');
    }

    public function test_unchecked_comment_sends_neither_channel_even_when_whatsapp_automation_is_enabled(): void
    {
        Notification::fake();
        Bus::fake();
        $operator = $this->user();
        $requester = $this->user('Solicitante');
        $ticket = $this->ticket($requester);
        app(TicketWhatsAppAutomations::class)->save('comment', true, 'Resposta {numero}');

        $this->actingAs($operator)->patch('/tickets/'.$ticket->id, [
            'title' => $ticket->title,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status_id' => $ticket->status_id,
            'due_at' => null,
            'comment_body' => 'Resposta sem aviso.',
            'comment_visibility' => 'public',
            'comment_notify_requester' => '0',
        ])->assertRedirect();

        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);
        $this->assertDatabaseHas('comments', ['ticket_id' => $ticket->id, 'body' => 'Resposta sem aviso.']);
    }

    public function test_unchecked_standalone_comment_does_not_send_whatsapp_or_email(): void
    {
        Notification::fake();
        Bus::fake();
        $operator = $this->user();
        $requester = $this->user('Solicitante');
        $ticket = $this->ticket($requester);
        app(TicketWhatsAppAutomations::class)->save('comment', true, 'Resposta {numero}');

        $this->actingAs($operator)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Resposta independente sem notificação.',
            'notify_requester' => '0',
        ])->assertRedirect();

        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);
    }

    public function test_checked_standalone_comment_sends_both_channels(): void
    {
        Notification::fake();
        Bus::fake();
        $operator = $this->user();
        $requester = $this->user('Solicitante');
        $ticket = $this->ticket($requester);
        app(TicketWhatsAppAutomations::class)->save('comment', true, 'Resposta {numero}');

        $this->actingAs($operator)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Resposta com notificação.',
            'notify_requester' => '1',
        ])->assertRedirect();

        Notification::assertSentTo($requester, TicketActivityNotification::class, 1);
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, 1);
    }

    public function test_comment_plus_status_unchecked_does_not_send_unwanted_requester_messages(): void
    {
        Notification::fake();
        Bus::fake();
        $operator = $this->user();
        $requester = $this->user('Solicitante');
        $ticket = $this->ticket($requester);
        app(TicketWhatsAppAutomations::class)->save('comment', true, 'Resposta {numero}');
        app(TicketWhatsAppAutomations::class)->save('status', true, 'Novo status {status}');

        $this->actingAs($operator)->patch('/tickets/'.$ticket->id, [
            'title' => $ticket->title,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status_id' => Status::system('in_progress')->id,
            'due_at' => null,
            'notify_requester' => '1',
            'comment_body' => 'Comentário sem aviso.',
            'comment_visibility' => 'public',
            'comment_notify_requester' => '0',
        ])->assertRedirect();

        $this->assertSame('in_progress', $ticket->fresh()->status?->system_key);
        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);
    }

    public function test_whatsapp_comment_disabled_does_not_block_email_selected_by_operator(): void
    {
        Notification::fake();
        Bus::fake();
        $operator = $this->user();
        $requester = $this->user('Solicitante');
        $ticket = $this->ticket($requester);
        app(TicketWhatsAppAutomations::class)->save('comment', false, 'Resposta {numero}');

        $this->actingAs($operator)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Resposta quando WhatsApp está desativado.',
            'notify_requester' => '1',
        ])->assertRedirect();

        Notification::assertSentTo($requester, TicketActivityNotification::class, 1);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);
    }

    public function test_internal_note_never_notifies_requester_even_when_checkbox_is_sent(): void
    {
        Notification::fake();
        Bus::fake();
        $operator = $this->user();
        $requester = $this->user('Solicitante');
        $ticket = $this->ticket($requester);
        app(TicketWhatsAppAutomations::class)->save('comment', true, 'Resposta {numero}');

        $this->actingAs($operator)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'internal',
            'body' => 'Nota interna sigilosa.',
            'notify_requester' => '1',
        ])->assertRedirect();

        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);
    }
}
