<?php

namespace Tests\Feature;

use App\Jobs\SendAssigneeReplyWhatsApp;
use App\Jobs\SendTicketWhatsAppAutomation;
use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Services\TicketWhatsAppAutomations;
use App\Services\WhatsAppConnection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AssigneeReplyWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, bool $superAdmin = false): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('wa-equipe-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $superAdmin ? Role::where('name', 'Super Admin')->value('id') : null,
            'active' => true,
        ]);
    }

    private function ticket(User $assignee, ?User $requester = null, ?ConnectedSystem $system = null): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => $system ? 'integration' : 'internal',
            'title' => 'Retorno sobre atendimento',
            'description' => 'Solicitação inicial',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'assignee_id' => $assignee->id,
            'requester_user_id' => $requester?->id,
            'requester_name' => $requester?->name ?? 'Solicitante externo',
            'requester_email' => $requester?->email ?? 'cliente@example.test',
            'requester_whatsapp' => '5511988887777',
            'system_id' => $system?->id,
            'external_requester_id' => $system ? 'estudio-franca-17' : null,
        ]);
    }

    private function integration(): ConnectedSystem
    {
        $company = Company::create(['name' => 'Cliente integrado', 'active' => false]);
        $system = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Estúdio França',
            'active' => true,
        ]);
        $system->forceFill(['api_token_hash' => hash('sha256', 'token-interno-teste')])->save();

        return $system;
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer token-interno-teste',
            'X-External-User-Id' => 'estudio-franca-17',
            'X-External-User-Role' => 'user',
        ];
    }

    private function connection(): WhatsAppConnection
    {
        $connection = app(WhatsAppConnection::class);
        $connection->save([
            'base_url' => 'https://evolution.example.test',
            'instance' => 'sutoorii-tickets',
            'api_key' => 'segredo-de-teste',
        ]);

        return $connection;
    }

    public function test_responsible_user_can_save_private_whatsapp_and_disable_notifications(): void
    {
        $responsible = $this->user('Responsável');
        $this->actingAs($responsible)->get('/meu-perfil/notificacoes')
            ->assertOk()->assertSee('Notificações por WhatsApp');

        $this->actingAs($responsible)->patch('/meu-perfil/notificacoes', [
            'whatsapp' => '(15) 99999-8888',
            'whatsapp_reply_enabled' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $responsible->refresh();
        $this->assertSame('5515999998888', $responsible->whatsapp);
        $this->assertNotSame($responsible->whatsapp, $responsible->getRawOriginal('whatsapp'));
        $this->assertTrue($responsible->whatsapp_reply_enabled);

        $this->actingAs($responsible)->patch('/meu-perfil/notificacoes', [
            'whatsapp' => '5515999998888',
            'whatsapp_reply_enabled' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($responsible->fresh()->whatsapp_reply_enabled);

        $this->actingAs($responsible)->patch('/meu-perfil/notificacoes', [
            'whatsapp' => 'numero-sem-ddd',
            'whatsapp_reply_enabled' => '1',
        ])->assertSessionHasErrors('whatsapp');
        $this->assertSame('5515999998888', $responsible->fresh()->whatsapp);
    }

    public function test_admin_can_register_assignee_whatsapp_without_leaking_it_to_audit_log(): void
    {
        $admin = $this->user('Admin', true);
        $responsible = $this->user('Técnico');
        $this->actingAs($admin)->get('/admin/usuarios/'.$responsible->id.'/editar')
            ->assertOk()->assertSee('WhatsApp da equipe');

        $this->actingAs($admin)->patch('/admin/usuarios/'.$responsible->id, [
            'name' => $responsible->name,
            'email' => $responsible->email,
            'role_id' => null,
            'department_id' => null,
            'active' => '1',
            'whatsapp' => '(15) 99999-8888',
            'whatsapp_reply_enabled' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('5515999998888', $responsible->fresh()->whatsapp);
        $this->assertDatabaseMissing('audit_logs', ['new_values' => '5515999998888']);
    }

    public function test_client_reply_from_integrated_system_queues_whatsapp_for_assignee_not_requester(): void
    {
        Bus::fake();
        Notification::fake();
        $assignee = $this->user('Responsável');
        $assignee->update(['whatsapp' => '5515999998888']);
        $ticket = $this->ticket($assignee, null, $this->integration());

        // A notificação da equipe independe da automação de comentário do cliente.
        app(TicketWhatsAppAutomations::class)->save('comment', false, 'Cliente: {numero}');

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/tickets/'.$ticket->number.'/comments', [
                'body' => 'Tenho mais uma informação.',
                'external_message_id' => 'um-id',
            ])->assertCreated();

        Notification::assertSentTo($assignee, TicketActivityNotification::class);
        Bus::assertDispatched(SendAssigneeReplyWhatsApp::class, 1);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);

        // Reenvios idempotentes da mesma resposta não disparam outro aviso.
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/tickets/'.$ticket->number.'/comments', [
                'body' => 'Tenho mais uma informação.',
                'external_message_id' => 'um-id',
            ])->assertOk();
        Bus::assertDispatched(SendAssigneeReplyWhatsApp::class, 1);
    }

    public function test_customer_reply_without_assignee_phone_keeps_email_and_bell_only(): void
    {
        Bus::fake();
        Notification::fake();
        $assignee = $this->user('Responsável sem WhatsApp');
        $requester = $this->user('Solicitante interno');
        $ticket = $this->ticket($assignee, $requester);

        $this->actingAs($requester)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public', 'body' => 'Nova informação do cliente.',
        ])->assertRedirect();

        Notification::assertSentTo($assignee, TicketActivityNotification::class);
        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
        Bus::assertNotDispatched(SendAssigneeReplyWhatsApp::class);
    }

    public function test_reply_job_sends_once_to_assignee_only_without_exposing_comment_body(): void
    {
        $connection = $this->connection();
        Http::fake(['evolution.example.test/message/sendText/*' => Http::response(['key' => ['id' => 'ok']], 200)]);
        $assignee = $this->user('Responsável');
        $assignee->update(['whatsapp' => '5515999998888']);
        $ticket = $this->ticket($assignee, null, $this->integration());

        $comment = $ticket->comments()->create([
            'user_id' => null,
            'visibility' => 'public',
            'body' => 'Mensagem privada de atendimento não pode ir no WhatsApp.',
            'source' => 'integration',
        ]);

        $job = new SendAssigneeReplyWhatsApp($ticket->id, $comment->id, $assignee->id);
        $job->handle($connection, app(TicketWhatsAppAutomations::class));
        $job->handle($connection, app(TicketWhatsAppAutomations::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) =>
            $request['number'] === '5515999998888'
            && str_contains($request['text'], '#'.$ticket->number)
            && !str_contains($request['text'], 'Mensagem privada')
            && $request['number'] !== $ticket->requester_whatsapp);
        $this->assertDatabaseHas('whatsapp_notification_deliveries', [
            'ticket_id' => $ticket->id,
            'event' => 'responsible_reply',
        ]);
    }

    public function test_whatsapp_reply_obeys_opt_out_automation_and_assignee_changes(): void
    {
        $connection = $this->connection();
        Http::fake(['*' => Http::response(['key' => ['id' => 'ok']], 200)]);
        $assignee = $this->user('Responsável');
        $assignee->update(['whatsapp' => '5515999998888']);
        $ticket = $this->ticket($assignee, null, $this->integration());
        $comment = $ticket->comments()->create([
            'user_id' => null, 'visibility' => 'public',
            'body' => 'Cliente respondeu.', 'source' => 'integration',
        ]);
        $job = new SendAssigneeReplyWhatsApp($ticket->id, $comment->id, $assignee->id);
        $automations = app(TicketWhatsAppAutomations::class);

        $assignee->update(['whatsapp_reply_enabled' => false]);
        $job->handle($connection, $automations);
        Http::assertNothingSent();

        $assignee->update(['whatsapp_reply_enabled' => true]);
        $automations->save('responsible_reply', false, 'Resposta ao {numero}');
        $job->handle($connection, $automations);
        Http::assertNothingSent();

        $automations->save('responsible_reply', true, 'Resposta ao {numero}');
        $ticket->update(['assignee_id' => $this->user('Outro responsável')->id]);
        $job->handle($connection, $automations);
        Http::assertNothingSent();
    }
}
