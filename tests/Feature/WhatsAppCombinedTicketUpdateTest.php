<?php

namespace Tests\Feature;

use App\Jobs\SendTicketWhatsAppAutomation;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWhatsAppAutomations;
use App\Services\WhatsAppConnection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WhatsAppCombinedTicketUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $role = 'Super Admin'): User
    {
        return User::create([
            'name' => 'Atendente de teste',
            'email' => uniqid('ticket-editor-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => Role::where('name', $role)->value('id'),
            'active' => true,
        ]);
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Sistema indisponível',
            'description' => 'Erro no login',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'requester_whatsapp' => '5515999998888',
            'due_at' => now()->addDays(2),
        ]);
    }

    private function editPayload(Ticket $ticket, string $status = 'in_progress'): array
    {
        return [
            'title' => $ticket->title,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status_id' => Status::system($status)->id,
            'due_at' => $ticket->due_at?->format('Y-m-d\TH:i'),
            'notify_requester' => '1',
        ];
    }

    public function test_ticket_page_has_one_button_to_save_comment_and_status_together(): void
    {
        $admin = $this->user();
        $ticket = $this->ticket();

        $response = $this->actingAs($admin)->get('/tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('id="ticketUnifiedForm"', false)
            ->assertSee('name="comment_body"', false)
            ->assertSee('name="status_id"', false)
            ->assertDontSee('Enviar comentário');
        $this->assertSame(1, substr_count($response->getContent(), '>Salvar alterações</button>'));
    }

    public function test_combined_public_comment_and_status_change_saves_both_and_sends_only_one_whatsapp(): void
    {
        Notification::fake();
        Bus::fake();
        $admin = $this->user();
        $ticket = $this->ticket();
        $automations = app(TicketWhatsAppAutomations::class);
        $automations->save('comment', true, 'Resposta disponível no ticket {numero}.');
        $automations->save('status', true, 'Status atualizado: {status}.');

        $this->actingAs($admin)->patch('/tickets/'.$ticket->id, array_merge(
            $this->editPayload($ticket),
            [
                'comment_body' => 'Já aplicamos a correção e seguimos acompanhando.',
                'comment_visibility' => 'public',
                'comment_notify_requester' => '1',
            ]
        ))->assertRedirect();

        $this->assertSame('in_progress', $ticket->fresh()->status?->system_key);
        $this->assertDatabaseHas('comments', [
            'ticket_id' => $ticket->id,
            'visibility' => 'public',
            'body' => 'Já aplicamos a correção e seguimos acompanhando.',
        ]);
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, 1);
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class,
            fn ($job) => $job->event === 'comment' && $job->sourceId !== null);
    }

    public function test_status_only_queues_status_automation_and_internal_note_never_queues_comment_automation(): void
    {
        Notification::fake();
        Bus::fake();
        $admin = $this->user();
        $ticket = $this->ticket();
        $automations = app(TicketWhatsAppAutomations::class);
        $automations->save('comment', true, 'Nova resposta {numero}');
        $automations->save('status', true, 'Mudança: {status}');

        $this->actingAs($admin)->patch('/tickets/'.$ticket->id, $this->editPayload($ticket))
            ->assertRedirect();
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, 1);
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class,
            fn ($job) => $job->event === 'status' && $job->statusName === 'Em andamento');

        Bus::fake();
        $ticket->refresh();
        $this->actingAs($admin)->patch('/tickets/'.$ticket->id, array_merge(
            $this->editPayload($ticket),
            ['comment_body' => 'Anotação interna.', 'comment_visibility' => 'internal']
        ))->assertRedirect();
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);
    }

    public function test_two_different_public_comments_get_separate_whatsapp_messages_and_same_comment_is_not_repeated(): void
    {
        $connection = app(WhatsAppConnection::class);
        $connection->save([
            'base_url' => 'https://evolution.example.test',
            'instance' => 'sutoorii-tickets',
            'api_key' => 'chave-teste',
        ]);
        Http::fake(['evolution.example.test/message/sendText/*' => Http::response(['key' => ['id' => 'abc']], 200)]);
        $ticket = $this->ticket();
        $automations = app(TicketWhatsAppAutomations::class);
        $automations->save('comment', true, "Ticket {numero}\nAssunto: {assunto}");
        $first = $ticket->comments()->create([
            'visibility' => 'public',
            'body' => 'Primeira resposta',
            'source' => 'web',
        ]);
        $second = $ticket->comments()->create([
            'visibility' => 'public',
            'body' => 'Segunda resposta',
            'source' => 'web',
        ]);

        $firstJob = new SendTicketWhatsAppAutomation($ticket->id, 'comment', $first->id);
        $firstJob->handle($connection);
        $firstJob->handle($connection);
        (new SendTicketWhatsAppAutomation($ticket->id, 'comment', $second->id))->handle($connection);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['number'] === '5515999998888'
            && $request['text'] === "Ticket {$ticket->number}\nAssunto: Sistema indisponível");
    }
}
