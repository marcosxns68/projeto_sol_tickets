<?php

namespace Tests\Feature;

use App\Jobs\SendIntegrationWebhook;
use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationWebhookSafeTest extends TestCase
{
    use RefreshDatabase;

    private function integration(string $url = 'https://93.184.216.34/hook'): ConnectedSystem
    {
        $company = Company::create(['name' => 'Interna '.uniqid(), 'active' => false]);
        return ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Estúdio França',
            'webhook_url' => $url,
            'active' => true,
        ]);
    }

    private function internalUser(array $permissions): User
    {
        $role = Role::create(['name' => 'Webhook '.uniqid(), 'active' => true]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'group' => explode('.', $key)[0]]);
            $role->permissions()->attach($permission->id);
        }

        return User::create([
            'name' => 'Atendente',
            'email' => uniqid('webhook-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function ticket(ConnectedSystem $integration, ?User $assignee = null): Ticket
    {
        $status = Status::create([
            'name' => 'Novo',
            'system_key' => 'new',
            'category' => 'open',
            'color' => '#6D28D9',
            'position' => 0,
            'active' => true,
        ]);

        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => 'Falha externa',
            'description' => 'Teste',
            'priority' => 'normal',
            'status_id' => $status->id,
            'assignee_id' => $assignee?->id,
            'system_id' => $integration->id,
            'external_requester_id' => '153',
            'external_reference' => 'ref-153',
        ]);
    }

    public function test_admin_can_generate_webhook_secret_once_and_it_is_not_stored_in_plain_text(): void
    {
        $permission = Permission::firstOrCreate(['key' => 'integrations.manage'], ['name' => 'Gerenciar integrações', 'group' => 'integrations']);
        $role = Role::create(['name' => 'Integrações '.uniqid(), 'active' => true]);
        $role->permissions()->attach($permission->id);
        $admin = User::create([
            'name' => 'Admin',
            'email' => uniqid('admin-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
        $integration = $this->integration();

        $response = $this->actingAs($admin)->post('/admin/integracoes/'.$integration->id.'/novo-segredo-webhook');
        $response->assertRedirect('/admin/integracoes');

        $secret = session('generated_webhook_secret');
        $this->assertIsString($secret);
        $this->assertStringStartsWith('whsec_', $secret);
        $integration->refresh();
        $this->assertNotSame($secret, $integration->webhook_secret);
        $this->assertSame($secret, $integration->webhookSigningSecret());
    }

    public function test_only_internal_public_comment_queues_signed_webhook(): void
    {
        Queue::fake();
        $integration = $this->integration();
        $integration->issueWebhookSecret();
        $user = $this->internalUser(['tickets.view_all', 'tickets.comment', 'tickets.internal_note']);
        $ticket = $this->ticket($integration, $user);

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Já estamos verificando.',
        ])->assertRedirect();

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'internal',
            'body' => 'Nota só da equipe.',
        ])->assertRedirect();

        Queue::assertPushed(SendIntegrationWebhook::class, 1);
        Queue::assertPushed(SendIntegrationWebhook::class, fn ($job) => $job->event === 'ticket.comment.created');
    }

    public function test_internal_close_queues_webhook_for_integrated_ticket(): void
    {
        Queue::fake();
        $integration = $this->integration();
        $integration->issueWebhookSecret();
        $user = $this->internalUser(['tickets.view_all', 'tickets.close']);
        $ticket = $this->ticket($integration, $user);
        Status::create([
            'name' => 'Fechado',
            'system_key' => 'closed',
            'category' => 'completed',
            'color' => '#15803D',
            'position' => 8,
            'active' => true,
        ]);

        $this->actingAs($user)->post('/tickets/'.$ticket->id.'/fechar')->assertRedirect();

        Queue::assertPushed(SendIntegrationWebhook::class, fn ($job) => $job->event === 'ticket.closed');
    }

    public function test_webhook_job_signs_payload_and_has_retry_policy(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $integration = $this->integration();
        $secret = $integration->issueWebhookSecret();
        $payload = [
            'event' => 'ticket.comment.created',
            'ticket_number' => '26091234',
            'comment' => ['body' => 'Resposta pública'],
        ];
        $job = new SendIntegrationWebhook($integration->id, 'ticket.comment.created', $payload);

        $job->handle();

        $this->assertSame(5, $job->tries);
        Http::assertSent(function ($request) use ($secret, $payload) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $request->url() === 'https://93.184.216.34/hook'
                && $request->hasHeader('X-Sutoorii-Event', 'ticket.comment.created')
                && $request->hasHeader('X-Sutoorii-Signature', 'sha256='.hash_hmac('sha256', $body, $secret));
        });
    }

    public function test_private_webhook_destination_is_blocked(): void
    {
        Http::fake();
        $integration = $this->integration('http://127.0.0.1/hook');
        $integration->issueWebhookSecret();
        $job = new SendIntegrationWebhook($integration->id, 'ticket.test', ['event' => 'ticket.test']);

        $this->expectException(\RuntimeException::class);
        $job->handle();
        Http::assertNothingSent();
    }
}
