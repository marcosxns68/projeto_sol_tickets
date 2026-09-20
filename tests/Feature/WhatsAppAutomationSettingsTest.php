<?php

namespace Tests\Feature;

use App\Jobs\SendTicketOpenedWhatsApp;
use App\Jobs\SendTicketWhatsAppAutomation;
use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWhatsAppAutomations;
use App\Services\WhatsAppConnection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppAutomationSettingsTest extends TestCase
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
            'name' => 'Configurações WA',
            'email' => uniqid('wa-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'Teste123456',
            'role_id' => Role::where('name', $role)->value('id'),
            'active' => true,
        ]);
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Incidente',
            'description' => 'Falha',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'requester_whatsapp' => '5515999998888',
        ]);
    }

    private function connection(): WhatsAppConnection
    {
        $connection = app(WhatsAppConnection::class);
        $connection->save([
            'base_url' => 'https://evolution.example.test',
            'instance' => 'sutoorii-tickets',
            'api_key' => 'teste-segredo',
        ]);
        return $connection;
    }

    public function test_super_admin_only_can_manage_four_automation_messages_independently(): void
    {
        $service = app(TicketWhatsAppAutomations::class);
        $this->assertTrue($service->enabled('opened'));
        $this->assertFalse($service->enabled('closed'));
        $this->assertFalse($service->enabled('comment'));
        $this->assertFalse($service->enabled('status'));
        $this->assertSame("> Sutoorii Tickets\n\nSeu ticket de número {numero} foi aberto com sucesso.\nAssunto: {assunto}", $service->template('opened'));

        $manager = $this->user('Gestor');
        $this->actingAs($manager)->get('/admin/configuracoes/notificacoes')->assertForbidden();
        $this->actingAs($manager)->patch('/admin/configuracoes/notificacoes/whatsapp/closed', [
            'enabled' => '1', 'message' => 'Fechado {numero}',
        ])->assertForbidden();

        $admin = $this->user();
        $this->actingAs($admin)->get('/admin/configuracoes/notificacoes')
            ->assertOk()->assertSee('Abertura do ticket')->assertSee('Fechamento do ticket')
            ->assertSee('Novo comentário público')->assertSee('Mudança de status')
            ->assertSee('name="automations[closed][message]"', false);

        $this->actingAs($admin)->patch('/admin/configuracoes/notificacoes/whatsapp/closed', [
            'enabled' => '1',
            'message' => "> Sutoorii Tickets\n\nO ticket {numero} foi encerrado.",
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($service->enabled('opened'));
        $this->assertTrue($service->enabled('closed'));
        $this->assertSame("> Sutoorii Tickets\n\nO ticket {numero} foi encerrado.", $service->template('closed'));
        $ticket = $this->ticket();
        $this->assertSame("> Sutoorii Tickets\n\nO ticket {$ticket->number} foi encerrado.", $service->render('closed', $ticket));

        $this->actingAs($admin)->patch('/admin/configuracoes/notificacoes/whatsapp/opened', [
            'message' => 'Recebemos o chamado {numero}.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($service->enabled('opened'));
        $this->assertTrue($service->enabled('closed'));
        $this->assertSame('Recebemos o chamado {numero}.', $service->template('opened'));
    }

    public function test_invalid_configuration_does_not_change_saved_message(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->patch('/admin/configuracoes/notificacoes/whatsapp/closed', [
            'enabled' => '1', 'message' => '{nome} {numero}',
        ])->assertSessionHasErrors('message');

        $this->assertFalse(app(TicketWhatsAppAutomations::class)->enabled('closed'));
        $this->assertSame(TicketWhatsAppAutomations::DEFAULT_TEMPLATES['closed'],
            app(TicketWhatsAppAutomations::class)->template('closed'));

        $this->actingAs($admin)->patch('/admin/configuracoes/notificacoes/whatsapp/closed', [
            'enabled' => '1', 'message' => "   ",
        ])->assertSessionHasErrors('message');
        $this->assertFalse(app(TicketWhatsAppAutomations::class)->enabled('closed'));

        $this->actingAs($admin)->patch('/admin/configuracoes/notificacoes/whatsapp/unknown', [
            'enabled' => '1', 'message' => 'Texto {numero}',
        ])->assertNotFound();
    }

    public function test_opened_message_uses_saved_template_and_obeys_switch(): void
    {
        $connection = $this->connection();
        Http::fake(['evolution.example.test/message/sendText/*' => Http::response(['key' => ['id' => 'abc']], 200)]);
        $ticket = $this->ticket();
        $service = app(TicketWhatsAppAutomations::class);
        $service->save('opened', true, "Chamado: {numero}\nRecebido.");

        $job = new SendTicketOpenedWhatsApp($ticket->id);
        $job->handle($connection);
        $job->handle($connection);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) =>
            $request['number'] === '5515999998888'
            && $request['text'] === 'Chamado: '.$ticket->number."\nRecebido.");
        $this->assertNotNull($ticket->fresh()->whatsapp_opened_sent_at);

        $other = $this->ticket();
        $service->save('opened', false, 'Texto {numero}');
        (new SendTicketOpenedWhatsApp($other->id))->handle($connection);
        Http::assertSentCount(1);
        $this->assertNull($other->fresh()->whatsapp_opened_sent_at);
    }

    public function test_closing_internally_only_queues_message_on_first_real_transition_and_not_on_resolve(): void
    {
        Bus::fake();
        $admin = $this->user();
        $ticket = $this->ticket();
        $service = app(TicketWhatsAppAutomations::class);
        $service->save('closed', true, 'Fechado: {numero}');
        $ticket->update(['assignee_id' => $admin->id]);
        $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/resolver')->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status?->system_key);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);

        $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/fechar')->assertRedirect();
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, 1);
        $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/fechar')->assertRedirect();
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, 1);
    }

    public function test_closing_from_integrated_system_uses_requester_number_and_respects_disabled_switch(): void
    {
        Bus::fake();
        $company = Company::create(['name' => 'Empresa', 'active' => true]);
        $integration = ConnectedSystem::create(['name' => 'Estúdio França', 'company_id' => $company->id, 'active' => true]);
        $integration->forceFill(['api_token_hash' => hash('sha256', 'token-franca')])->save();
        $ticket = $this->ticket();
        $ticket->update(['origin'=>'integration','system_id'=>$integration->id,'external_requester_id'=>'153']);

        $headers = [
            'Authorization' => 'Bearer token-franca',
            'X-External-User-Id' => '153',
            'X-External-User-Role' => 'user',
        ];

        $this->withHeaders($headers)->postJson('/api/v1/tickets/'.$ticket->number.'/close')->assertOk();
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);

        $this->withHeaders($headers)->postJson('/api/v1/tickets/'.$ticket->number.'/reopen')->assertOk()->assertJsonPath('ticket.status_key', 'new');
        app(TicketWhatsAppAutomations::class)->save('closed', true, 'Ticket {numero} fechado.');
        $this->assertTrue(app(TicketWhatsAppAutomations::class)->enabled('closed'));
        $this->withHeaders($headers)->postJson('/api/v1/tickets/'.$ticket->number.'/close')->assertOk()->assertJsonPath('ticket.status_key', 'closed');
        $this->withHeaders($headers)->postJson('/api/v1/tickets/'.$ticket->number.'/close')->assertOk();
        Bus::assertDispatched(SendTicketWhatsAppAutomation::class, 1);
    }

    public function test_closed_job_only_sends_for_a_closed_ticket_and_not_twice(): void
    {
        $connection = $this->connection();
        $service = app(TicketWhatsAppAutomations::class);
        $service->save('closed', true, "Sutoorii Tickets\n\nO ticket {numero} foi fechado.");
        $ticket = $this->ticket();
        Http::fake(['evolution.example.test/message/sendText/*' => Http::response(['key' => ['id' => 'abc']], 200)]);

        $job = new SendTicketWhatsAppAutomation($ticket->id, 'closed');
        $job->handle($connection);
        Http::assertNothingSent();

        $ticket->update(['status_id' => Status::system('closed')->id, 'completed_at' => now()]);
        $job->handle($connection);
        $job->handle($connection);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['text'] === "Sutoorii Tickets\n\nO ticket {$ticket->number} foi fechado.");
        $this->assertNotNull($ticket->fresh()->whatsapp_closed_sent_at);
    }
    public function test_all_automations_start_collapsed_and_one_save_updates_all_together(): void
    {
        $admin = $this->user();
        $page = $this->actingAs($admin)->get('/admin/configuracoes/notificacoes')->assertOk();
        $html = $page->getContent();

        $this->assertSame(4, substr_count($html, 'data-notification-automation='));
        $this->assertSame(1, substr_count($html, 'class="admin-editor"'));
        $this->assertSame(1, substr_count($html, 'Salvar configurações'));
        $this->assertSame(1, substr_count($html, 'Variáveis:'));
        $this->assertStringNotContainsString('data-notification-automation="opened" open', $html);
        $this->assertStringNotContainsString('data-notification-automation="closed" open', $html);
        $this->assertStringNotContainsString('data-notification-automation="comment" open', $html);
        $this->assertStringNotContainsString('data-notification-automation="status" open', $html);

        $payload = [
            'automations' => [
                'opened' => ['enabled' => '1', 'message' => 'Novo {numero}: {assunto}'],
                'closed' => ['enabled' => '1', 'message' => 'Fechado {numero}: {assunto}'],
                'comment' => ['enabled' => '0', 'message' => 'Comentário no {numero}: {assunto}'],
                'status' => ['enabled' => '1', 'message' => '{numero} passou para {status}'],
            ],
        ];
        $this->actingAs($this->user('Gestor'))
            ->patch('/admin/configuracoes/notificacoes', $payload)->assertForbidden();

        $this->actingAs($admin)->patch('/admin/configuracoes/notificacoes', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $service = app(TicketWhatsAppAutomations::class);
        $this->assertSame('Novo {numero}: {assunto}', $service->template('opened'));
        $this->assertSame('Fechado {numero}: {assunto}', $service->template('closed'));
        $this->assertSame('Comentário no {numero}: {assunto}', $service->template('comment'));
        $this->assertSame('{numero} passou para {status}', $service->template('status'));
        $this->assertTrue($service->enabled('opened'));
        $this->assertTrue($service->enabled('closed'));
        $this->assertFalse($service->enabled('comment'));
        $this->assertTrue($service->enabled('status'));
    }

    public function test_invalid_message_does_not_partially_save_other_automations(): void
    {
        $admin = $this->user();
        $messages = [];
        foreach (TicketWhatsAppAutomations::DEFAULT_TEMPLATES as $event => $template) {
            $messages[$event] = ['enabled' => '1', 'message' => $template];
        }
        $messages['opened']['message'] = 'Alterado {numero}';
        $messages['status']['message'] = 'Status {variavel_inexistente}';

        $this->actingAs($admin)->patch('/admin/configuracoes/notificacoes', ['automations' => $messages])
            ->assertSessionHasErrors('automations.status.message');
        $service = app(TicketWhatsAppAutomations::class);
        $this->assertSame(TicketWhatsAppAutomations::DEFAULT_TEMPLATES['opened'], $service->template('opened'));
        $this->assertFalse($service->enabled('closed'));
    }

}
