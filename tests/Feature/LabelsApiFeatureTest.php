<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsApiFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_can_define_default_labels_for_created_tickets(): void
    {
        $department = Department::query()->create(['name' => 'Suporte']);
        $label = Label::query()->create(['name' => 'Estúdio França', 'color' => '#6d28d9']);
        $integration = ConnectedSystem::query()->create([
            'name' => 'Estúdio França',
            'slug' => 'estudio-franca',
            'api_key_hash' => hash('sha256', 'test-key'),
            'active' => true,
            'default_department_id' => $department->id,
        ]);
        $integration->defaultLabels()->attach($label->id);

        $response = $this->withHeader('Authorization', 'Bearer test-key')
            ->postJson('/api/v1/tickets', [
                'title' => 'Erro de acesso',
                'description' => 'Cliente não consegue entrar.',
                'requester_name' => 'Cliente Teste',
                'requester_email' => 'cliente@example.com',
                'external_reference' => 'EXT-001',
            ]);

        $response->assertCreated();
        $ticketId = $response->json('data.id') ?? $response->json('id');

        $this->assertDatabaseHas('label_ticket', [
            'ticket_id' => $ticketId,
            'label_id' => $label->id,
        ]);
    }
}
