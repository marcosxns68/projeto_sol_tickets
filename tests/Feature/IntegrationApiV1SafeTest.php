<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntegrationApiV1SafeTest extends TestCase
{
    use RefreshDatabase;

    private function makeStatus(string $key = 'new', string $name = 'Novo', string $category = 'open'): Status
    {
        return Status::create([
            'name' => $name,
            'system_key' => $key,
            'category' => $category,
            'color' => '#6D28D9',
            'position' => 0,
            'active' => true,
        ]);
    }

    private function integration(string $name, string $token, bool $active = true): ConnectedSystem
    {
        $company = Company::create(['name' => 'Interna '.uniqid(), 'active' => false]);
        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => $name,
            'active' => $active,
        ]);
        $integration->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        return $integration;
    }

    private function headers(string $token, string $userId = '10', string $role = 'user'): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-External-User-Id' => $userId,
            'X-External-User-Role' => $role,
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'requester_name' => 'João Silva',
            'requester_email' => 'joao@example.com',
            'title' => 'Erro ao gerar contrato',
            'description' => 'A geração não conclui.',
            'priority' => 'normal',
        ], $overrides);
    }

    public function test_valid_key_creates_ticket_idempotently_with_aamm0000_number(): void
    {
        $this->makeStatus();
        $integration = $this->integration('Estúdio França', 'token-franca');
        $payload = $this->payload(['external_reference' => 'contrato-184']);

        $first = $this->withHeaders($this->headers('token-franca', '153'))->postJson('/api/v1/tickets', $payload);
        $first->assertCreated()->assertJsonPath('ticket.external_reference', 'contrato-184');
        $number = $first->json('ticket.number');
        $this->assertMatchesRegularExpression('/^'.now()->format('ym').'\d{4}$/', $number);

        $second = $this->withHeaders($this->headers('token-franca', '153'))->postJson('/api/v1/tickets', $payload);
        $second->assertOk()->assertJsonPath('ticket.number', $number);
        $this->assertSame(1, Ticket::where('system_id', $integration->id)->where('external_reference', 'contrato-184')->count());
    }

    public function test_external_reference_is_optional_and_same_reference_is_allowed_under_different_keys(): void
    {
        $this->makeStatus();
        $a = $this->integration('A', 'token-a');
        $b = $this->integration('B', 'token-b');

        $this->withHeaders($this->headers('token-a', '1'))
            ->postJson('/api/v1/tickets', $this->payload(['title' => 'Sem referência']))
            ->assertCreated()
            ->assertJsonPath('ticket.external_reference', null);

        $same = $this->payload(['external_reference' => 'mesma-ref']);
        $this->withHeaders($this->headers('token-a', '1'))->postJson('/api/v1/tickets', $same)->assertCreated();
        $this->withHeaders($this->headers('token-b', '1'))->postJson('/api/v1/tickets', $same)->assertCreated();

        $this->assertSame(1, Ticket::where('system_id', $a->id)->where('external_reference', 'mesma-ref')->count());
        $this->assertSame(1, Ticket::where('system_id', $b->id)->where('external_reference', 'mesma-ref')->count());
    }

    public function test_user_sees_only_own_tickets_and_manager_sees_all_only_for_same_key(): void
    {
        $status = $this->makeStatus();
        $a = $this->integration('A', 'token-a');
        $b = $this->integration('B', 'token-b');

        Ticket::create(['number' => Ticket::nextNumber(), 'origin' => 'integration', 'title' => 'A1', 'description' => 'x', 'priority' => 'normal', 'status_id' => $status->id, 'system_id' => $a->id, 'external_requester_id' => '1']);
        Ticket::create(['number' => Ticket::nextNumber(), 'origin' => 'integration', 'title' => 'A2', 'description' => 'x', 'priority' => 'normal', 'status_id' => $status->id, 'system_id' => $a->id, 'external_requester_id' => '2']);
        Ticket::create(['number' => Ticket::nextNumber(), 'origin' => 'integration', 'title' => 'B1', 'description' => 'x', 'priority' => 'normal', 'status_id' => $status->id, 'system_id' => $b->id, 'external_requester_id' => '1']);

        $this->withHeaders($this->headers('token-a', '1'))->getJson('/api/v1/tickets')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'A1');

        $manager = $this->withHeaders($this->headers('token-a', '999', 'manager'))->getJson('/api/v1/tickets');
        $manager->assertOk()->assertJsonCount(2, 'data');
        $this->assertNotContains('B1', collect($manager->json('data'))->pluck('title')->all());
    }

    public function test_listing_supports_priority_status_and_pagination_filters(): void
    {
        $status = $this->makeStatus();
        $integration = $this->integration('A', 'token-a');
        foreach ([['Alta 1', 'high'], ['Alta 2', 'high'], ['Normal', 'normal']] as [$title, $priority]) {
            Ticket::create(['number' => Ticket::nextNumber(), 'origin' => 'integration', 'title' => $title, 'description' => 'x', 'priority' => $priority, 'status_id' => $status->id, 'system_id' => $integration->id, 'external_requester_id' => '1']);
        }

        $response = $this->withHeaders($this->headers('token-a', '1'))
            ->getJson('/api/v1/tickets?priority=high&status=new&per_page=1&page=1');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1);
    }

    public function test_invalid_or_inactive_key_is_rejected(): void
    {
        $this->integration('Inativa', 'token-off', false);
        $this->withHeaders($this->headers('wrong'))->getJson('/api/v1/tickets')->assertUnauthorized();
        $this->withHeaders($this->headers('token-off'))->getJson('/api/v1/tickets')->assertForbidden();
    }

    public function test_rate_limit_and_last_activity_are_scoped_to_integration_key(): void
    {
        $this->makeStatus();
        $integration = $this->integration('A', 'token-a');
        $key = 'integration-api:'.$integration->id;
        RateLimiter::clear($key);
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->withHeaders($this->headers('token-a', '1'))->getJson('/api/v1/tickets')->assertStatus(429);
        RateLimiter::clear($key);
        $this->withHeaders($this->headers('token-a', '1'))->getJson('/api/v1/tickets')->assertOk();

        $this->assertDatabaseHas('settings', ['key' => 'integration.'.$integration->id.'.last_api_activity_at']);
    }

    public function test_default_department_configuration_is_used_when_creating_ticket(): void
    {
        $this->makeStatus();
        Department::create(['name' => 'Primeiro', 'active' => true]);
        $target = Department::create(['name' => 'Suporte integrações', 'active' => true]);
        $integration = $this->integration('A', 'token-a');
        DB::table('settings')->insert([
            'key' => 'integration.'.$integration->id.'.department_id',
            'value' => (string) $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($this->headers('token-a', '1'))->postJson('/api/v1/tickets', $this->payload());
        $response->assertCreated();
        $ticket = Ticket::where('number', $response->json('ticket.number'))->firstOrFail();
        $this->assertSame($target->id, $ticket->department_id);
    }

    public function test_public_comments_activity_lifecycle_and_attachments_work_without_exposing_internal_notes(): void
    {
        Storage::fake('local');
        $status = $this->makeStatus();
        $this->makeStatus('closed', 'Fechado', 'completed');
        $integration = $this->integration('França', 'token-franca');
        $ticket = Ticket::create(['number' => Ticket::nextNumber(), 'origin' => 'integration', 'title' => 'Ticket', 'description' => 'x', 'priority' => 'normal', 'status_id' => $status->id, 'system_id' => $integration->id, 'external_requester_id' => '153']);
        $ticket->comments()->create(['visibility' => 'internal', 'body' => 'segredo interno', 'source' => 'web']);

        $this->withHeaders($this->headers('token-franca', '153'))->postJson('/api/v1/tickets/'.$ticket->number.'/comments', [
            'body' => 'Retorno do cliente',
            'external_message_id' => 'msg-1',
        ])->assertCreated();

        $upload = $this->withHeaders($this->headers('token-franca', '153'))->post('/api/v1/tickets/'.$ticket->number.'/attachments', [
            'file' => UploadedFile::fake()->create('erro.txt', 4, 'text/plain'),
        ]);
        $upload->assertCreated();
        $attachmentId = $upload->json('attachment.id');

        $this->withHeaders($this->headers('token-franca', '153'))
            ->get('/api/v1/tickets/'.$ticket->number.'/attachments/'.$attachmentId)
            ->assertOk();

        $this->withHeaders($this->headers('token-franca', '153'))->post('/api/v1/tickets/'.$ticket->number.'/attachments', [
            'file' => UploadedFile::fake()->create('perigoso.exe', 4, 'application/octet-stream'),
        ])->assertUnprocessable();

        $activity = $this->withHeaders($this->headers('token-franca', '153'))->getJson('/api/v1/tickets/'.$ticket->number.'/activity');
        $activity->assertOk();
        $encoded = json_encode($activity->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Retorno do cliente', $encoded);
        $this->assertStringNotContainsString('segredo interno', $encoded);

        $this->withHeaders($this->headers('token-franca', '153'))->postJson('/api/v1/tickets/'.$ticket->number.'/close')->assertOk();
        $this->withHeaders($this->headers('token-franca', '153'))->postJson('/api/v1/tickets/'.$ticket->number.'/reopen')->assertOk();
    }
}
