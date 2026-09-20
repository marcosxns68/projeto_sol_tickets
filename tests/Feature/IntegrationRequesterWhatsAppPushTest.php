<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationRequesterWhatsAppPushTest extends TestCase
{
    use RefreshDatabase;

    private function system(string $token): ConnectedSystem
    {
        $company = Company::create(['name' => 'Cliente '.uniqid(), 'active' => true]);
        $system = ConnectedSystem::create([
            'company_id' => $company->id, 'name' => 'Estúdio França', 'active' => true,
        ]);
        $system->forceFill(['api_token_hash' => hash('sha256', $token)])->save();
        return $system;
    }

    private function ticket(ConnectedSystem $system, string $id, ?string $whatsapp = null): Ticket
    {
        $status = Status::firstOrCreate(['system_key' => 'new'], [
            'name' => 'Novo', 'category' => 'open', 'color' => '#6D28D9', 'position' => 0, 'active' => true,
        ]);
        return Ticket::create([
            'number' => Ticket::nextNumber(), 'origin' => 'integration',
            'title' => 'Chamado antigo', 'description' => 'Problema.', 'priority' => 'normal',
            'status_id' => $status->id, 'system_id' => $system->id,
            'external_requester_id' => $id, 'requester_whatsapp' => $whatsapp,
        ]);
    }

    private function headers(string $token, string $id = 'estudio-franca-17'): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-External-User-Id' => $id,
            'X-External-User-Role' => 'user',
        ];
    }

    public function test_valid_request_fills_only_own_tickets_in_same_integration_and_encrypts_phone(): void
    {
        Queue::fake();
        $system = $this->system('token-a');
        $otherSystem = $this->system('token-b');
        $one = $this->ticket($system, 'estudio-franca-17');
        $two = $this->ticket($system, 'estudio-franca-17');
        $alreadyFilled = $this->ticket($system, 'estudio-franca-17', '5511988887777');
        $anotherUser = $this->ticket($system, 'estudio-franca-18');
        $anotherIntegration = $this->ticket($otherSystem, 'estudio-franca-17');

        $this->withHeaders($this->headers('token-a'))->postJson('/api/v1/requester/whatsapp', [
            'requester_whatsapp' => '(15) 99999-8888',
        ])->assertOk()->assertJsonPath('tickets_preenchidos', 2);

        $this->assertSame('5515999998888', $one->fresh()->requester_whatsapp);
        $this->assertSame('5515999998888', $two->fresh()->requester_whatsapp);
        $this->assertNotSame('5515999998888', $one->fresh()->getRawOriginal('requester_whatsapp'));
        $this->assertSame('5511988887777', $alreadyFilled->fresh()->requester_whatsapp);
        $this->assertNull($anotherUser->fresh()->requester_whatsapp);
        $this->assertNull($anotherIntegration->fresh()->requester_whatsapp);

        $this->withHeaders($this->headers('token-a'))->postJson('/api/v1/requester/whatsapp', [
            'requester_whatsapp' => '5515999998888',
        ])->assertOk()->assertJsonPath('tickets_preenchidos', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_or_unauthorized_requests_cannot_fill_contact(): void
    {
        $system = $this->system('token-a');
        $ticket = $this->ticket($system, 'estudio-franca-17');

        $this->postJson('/api/v1/requester/whatsapp', [
            'requester_whatsapp' => '5515999998888',
        ])->assertUnauthorized();
        $this->withHeaders($this->headers('token-a', 'estudio-franca-18'))->postJson('/api/v1/requester/whatsapp', [
            'requester_whatsapp' => '5515999998888',
        ])->assertOk()->assertJsonPath('tickets_preenchidos', 0);
        $this->withHeaders($this->headers('token-a'))->postJson('/api/v1/requester/whatsapp', [
            'requester_whatsapp' => 'numero-invalido',
        ])->assertUnprocessable();
        $this->withHeaders($this->headers('token-a', 'qualquer-id'))->postJson('/api/v1/requester/whatsapp', [
            'requester_whatsapp' => '5515999998888',
        ])->assertUnprocessable();
        $this->assertNull($ticket->fresh()->requester_whatsapp);
    }
}
