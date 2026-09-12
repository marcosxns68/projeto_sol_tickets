<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsApiResponseFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_ticket_response_includes_labels(): void
    {
        $department = Department::query()->create(['name' => 'Suporte']);
        $label = Label::query()->create(['name' => 'Externo', 'color' => '#6d28d9']);
        $integration = ConnectedSystem::query()->create([
            'name' => 'Sistema',
            'slug' => 'sistema',
            'api_key_hash' => hash('sha256', 'test-key'),
            'active' => true,
            'default_department_id' => $department->id,
        ]);
        $integration->defaultLabels()->attach($label->id);

        $response = $this->withHeader('Authorization', 'Bearer test-key')->postJson('/api/v1/tickets', [
            'title' => 'Chamado',
            'description' => 'Teste',
            'requester_name' => 'Cliente',
            'requester_email' => 'cliente@example.com',
            'external_reference' => 'EXT-RESP-LABEL',
        ]);

        $response->assertCreated();
        $this->assertStringContainsString('Externo', $response->getContent());
    }
}
