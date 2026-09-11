<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntegrationAttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function headers(string $token, string $userId = '15'): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-External-User-Id' => $userId,
            'X-External-User-Role' => 'user',
        ];
    }

    public function test_external_user_can_upload_attachment_only_to_visible_ticket_without_storage_path_leak(): void
    {
        Storage::fake('local');
        $status = Status::create(['name' => 'Novo', 'system_key' => 'new', 'category' => 'open', 'color' => '#6D28D9', 'active' => true]);
        $integration = ConnectedSystem::create(['name' => 'França', 'active' => true]);
        $token = $integration->issueApiToken();
        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => 'Contrato',
            'description' => 'Erro',
            'priority' => 'normal',
            'status_id' => $status->id,
            'system_id' => $integration->id,
            'external_requester_id' => '15',
        ]);

        $response = $this->withHeaders($this->headers($token))->post('/api/v1/tickets/'.$ticket->number.'/attachments', [
            'file' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated()->assertJsonPath('attachment.name', 'evidencia.pdf');
        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('ticket-attachments', $encoded);
        $this->assertStringNotContainsString('"disk"', $encoded);
        $this->assertStringNotContainsString('"path"', $encoded);

        $attachment = $ticket->hasMany(\App\Models\Attachment::class)->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);

        $this->withHeaders($this->headers($token, '99'))->post('/api/v1/tickets/'.$ticket->number.'/attachments', [
            'file' => UploadedFile::fake()->create('outra.pdf', 20, 'application/pdf'),
        ])->assertNotFound();
    }
}
