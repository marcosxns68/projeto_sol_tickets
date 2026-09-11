<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\WebhookDeliveryProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function internalAgent(): User
    {
        $role = Role::create(['name' => 'Agente webhook '.uniqid(), 'active' => true]);
        $role->permissions()->attach(Permission::whereIn('key', ['tickets.comment', 'tickets.internal_note'])->pluck('id'));

        return User::create([
            'name' => 'Agente',
            'email' => uniqid('agente-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function ticketWithWebhook(User $agent): array
    {
        $status = Status::create(['name' => 'Novo', 'system_key' => 'new', 'category' => 'open', 'color' => '#6D28D9', 'active' => true]);
        $integration = ConnectedSystem::create([
            'name' => 'Estúdio França',
            'webhook_url' => 'https://8.8.8.8/sutoorii-webhook',
            'active' => true,
        ]);
        $integration->issueWebhookSecret();
        $token = $integration->issueApiToken();

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => 'Contrato',
            'description' => 'Erro',
            'priority' => 'normal',
            'status_id' => $status->id,
            'system_id' => $integration->id,
            'assignee_id' => $agent->id,
            'external_requester_id' => '15',
        ]);

        return [$integration, $ticket, $token];
    }

    public function test_public_internal_comment_sends_signed_webhook_but_internal_note_and_api_comment_do_not_echo(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('', 200)]);
        $agent = $this->internalAgent();
        [$integration, $ticket, $token] = $this->ticketWithWebhook($agent);

        $this->actingAs($agent)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Estamos analisando.',
        ])->assertRedirect();

        $this->assertDatabaseCount('webhook_deliveries', 1);
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame('ticket.comment.created', $delivery->event);
        $this->assertSame('delivered', $delivery->status);

        Http::assertSent(function ($request) use ($delivery) {
            return $request->url() === 'https://8.8.8.8/sutoorii-webhook'
                && $request->hasHeader('X-Sutoorii-Event', 'ticket.comment.created')
                && $request->hasHeader('X-Sutoorii-Delivery', $delivery->delivery_uuid)
                && str_starts_with($request->header('X-Sutoorii-Signature')[0] ?? '', 'sha256=');
        });

        $this->actingAs($agent)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'internal',
            'body' => 'Nota que o cliente não pode ver.',
        ])->assertRedirect();
        $this->assertDatabaseCount('webhook_deliveries', 1);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-External-User-Id' => '15',
            'X-External-User-Role' => 'user',
        ])->postJson('/api/v1/tickets/'.$ticket->number.'/comments', [
            'body' => 'Resposta pelo sistema de origem.',
            'external_message_id' => 'ext-1',
        ])->assertCreated();
        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_failed_delivery_becomes_dead_after_five_attempts_and_command_is_available(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('erro', 500)]);
        $agent = $this->internalAgent();
        [$integration, $ticket] = $this->ticketWithWebhook($agent);

        $delivery = WebhookDelivery::create([
            'system_id' => $integration->id,
            'ticket_id' => $ticket->id,
            'delivery_uuid' => (string) Str::uuid(),
            'event' => 'ticket.status.changed',
            'payload' => ['event' => 'ticket.status.changed', 'ticket_number' => $ticket->number],
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);

        $processor = app(WebhookDeliveryProcessor::class);
        for ($i = 0; $i < 5; $i++) {
            $processor->attempt($delivery->fresh());
        }

        $delivery->refresh();
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame('dead', $delivery->status);
        $this->assertNull($delivery->next_attempt_at);

        $this->artisan('tickets:webhooks-process')->assertSuccessful();
    }
}
