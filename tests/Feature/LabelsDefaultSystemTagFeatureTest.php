<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsDefaultSystemTagFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_created_ticket_keeps_integration_default_department_and_labels(): void
    {
        $department = Department::query()->create(['name' => 'Suporte']);
        $label = Label::query()->create(['name' => 'Sistema Externo', 'color' => '#6d28d9']);
        $integration = ConnectedSystem::query()->create([
            'name' => 'Sistema Externo',
            'slug' => 'sistema-externo',
            'api_key_hash' => hash('sha256', 'test-key'),
            'active' => true,
            'default_department_id' => $department->id,
        ]);
        $integration->defaultLabels()->attach($label->id);

        $response = $this->withHeader('Authorization', 'Bearer test-key')->postJson('/api/v1/tickets', [
            'title' => 'Chamado externo',
            'description' => 'Teste',
            'requester_name' => 'Cliente',
            'requester_email' => 'cliente@example.com',
            'external_reference' => 'EXT-DEPT-LABEL',
        ]);

        $response->assertCreated();
        $ticketId = $response->json('data.id') ?? $response->json('id');
        $this->assertDatabaseHas('tickets', ['id' => $ticketId, 'department_id' => $department->id]);
        $this->assertDatabaseHas('label_ticket', ['ticket_id' => $ticketId, 'label_id' => $label->id]);
    }
}
