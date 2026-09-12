<?php

namespace Tests\Feature;

use App\Jobs\SendIntegrationWebhook;
use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IntegrationSettings;
use App\Services\IntegrationWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InternalIntegrationTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $role = Role::create(['name' => 'Criador '.uniqid(), 'active' => true]);
        $permission = Permission::firstOrCreate(
            ['key' => 'tickets.create'],
            ['name' => 'Criar tickets', 'group' => 'tickets']
        );
        $role->permissions()->attach($permission->id);

        return User::create([
            'name' => 'Operador',
            'email' => uniqid('operador-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function initialStatus(): Status
    {
        return Status::create([
            'name' => 'Novo',
            'system_key' => 'new',
            'category' => 'open',
            'color' => '#6D28D9',
            'position' => 0,
            'active' => true,
        ]);
    }

    private function integration(bool $active = true): ConnectedSystem
    {
        $company = Company::create(['name' => 'Cliente '.uniqid(), 'active' => true]);
        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Estúdio França',
            'base_url' => 'https://93.184.216.34',
            'webhook_url' => 'https://93.184.216.34/webhook',
            'active' => $active,
        ]);
        $integration->issueWebhookSecret();
        return $integration;
    }

    private function ticketPayload(ConnectedSystem $integration, array $extra = []): array
    {
        return array_merge([
            'title' => 'Chamado criado pela equipe',
            'description' => 'Descrição do chamado.',
            'priority' => 'normal',
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'source_mode' => 'integration',
            'system_id' => $integration->id,
            'integration_target' => 'integration',
        ], $extra);
    }

    public function test_internal_operator_can_create_general_ticket_for_integration(): void
    {
        $user = $this->operator();
        $this->initialStatus();
        $integration = $this->integration();

        $response = $this->actingAs($user)->post('/tickets', $this->ticketPayload($integration));

        $response->assertRedirect();
        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame('internal', $ticket->origin);
        $this->assertSame($integration->id, $ticket->system_id);
        $this->assertNull($ticket->external_requester_id);
        $this->assertNull($ticket->requester_name);
        $this->assertNull($ticket->requester_email);
    }

    public function test_integration_defaults_apply_to_internal_integrated_ticket(): void
    {
        $user = $this->operator();
        $this->initialStatus();
        $integration = $this->integration();
        $department = Department::create(['name' => 'Suporte externo', 'active' => true]);
        $label = Label::create(['name' => 'Estúdio França', 'color' => '#7c3aed', 'system' => false]);
        $settings = app(IntegrationSettings::class);
        $settings->put($integration->id, 'department_id', $department->id);
        $settings->putLabelIds($integration->id, [$label->id]);

        $this->actingAs($user)->post('/tickets', $this->ticketPayload($integration))->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame($department->id, $ticket->department_id);
        $this->assertTrue($ticket->labels()->whereKey($label->id)->exists());
    }

    public function test_external_user_search_is_server_side_and_returns_sanitized_results(): void
    {
        $user = $this->operator();
        $integration = $this->integration();
        Http::fake([
            '*' => Http::response(['data' => [[
                'id' => 'estudio-franca-153',
                'name' => 'Maria França',
                'email' => 'maria@example.com',
                'telefone' => 'nao-deve-sair',
            ]]], 200),
        ]);

        $response = $this->actingAs($user)->getJson('/integracoes/'.$integration->id.'/usuarios?search=mar');

        $response->assertOk()->assertExactJson([
            'data' => [[
                'id' => 'estudio-franca-153',
                'name' => 'Maria França',
                'email' => 'maria@example.com',
            ]],
        ]);
        Http::assertSent(function ($request) use ($integration) {
            return str_contains($request->url(), '/api/sutoorii/users.php?search=mar')
                && $request->hasHeader('X-Sutoorii-Timestamp')
                && $request->hasHeader('X-Sutoorii-Signature')
                && !str_contains((string) $request->body(), $integration->webhookSigningSecret());
        });
    }

    public function test_internal_ticket_can_target_valid_external_user(): void
    {
        $user = $this->operator();
        $this->initialStatus();
        $integration = $this->integration();
        Http::fake([
            '*' => Http::response(['data' => [[
                'id' => 'estudio-franca-153',
                'name' => 'Maria França',
                'email' => 'maria@example.com',
            ]]], 200),
        ]);

        $response = $this->actingAs($user)->post('/tickets', $this->ticketPayload($integration, [
            'integration_target' => 'external_user',
            'external_requester_id' => 'estudio-franca-153',
        ]));

        $response->assertRedirect();
        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame($integration->id, $ticket->system_id);
        $this->assertSame('estudio-franca-153', $ticket->external_requester_id);
        $this->assertSame('Maria França', $ticket->requester_name);
        $this->assertSame('maria@example.com', $ticket->requester_email);
    }

    public function test_inactive_integration_cannot_be_used_for_internal_ticket(): void
    {
        $user = $this->operator();
        $this->initialStatus();
        $integration = $this->integration(false);

        $response = $this->actingAs($user)->post('/tickets', $this->ticketPayload($integration));

        $response->assertSessionHasErrors('system_id');
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_webhook_dispatcher_accepts_internal_ticket_linked_to_integration_but_not_pure_internal_ticket(): void
    {
        Queue::fake();
        $status = $this->initialStatus();
        $integration = $this->integration();
        $linked = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Vinculado',
            'description' => 'Teste',
            'priority' => 'normal',
            'status_id' => $status->id,
            'system_id' => $integration->id,
        ]);
        $pure = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Interno',
            'description' => 'Teste',
            'priority' => 'normal',
            'status_id' => $status->id,
        ]);

        $dispatcher = app(IntegrationWebhookDispatcher::class);
        $dispatcher->dispatch($linked, 'ticket.test');
        $dispatcher->dispatch($pure, 'ticket.test');

        Queue::assertPushed(SendIntegrationWebhook::class, 1);
    }
}
