<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsEmptyIntegrationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_labels_can_be_cleared(): void
    {
        $admin = User::factory()->create();
        $department = Department::query()->create(['name' => 'Suporte']);
        $integration = ConnectedSystem::query()->create([
            'name' => 'Sistema',
            'slug' => 'sistema',
            'api_key_hash' => hash('sha256', 'test-key'),
            'active' => true,
            'default_department_id' => $department->id,
        ]);
        $label = Label::query()->create(['name' => 'Padrão', 'color' => '#6d28d9']);
        $integration->defaultLabels()->attach($label->id);

        $this->actingAs($admin)->patch("/admin/integracoes/{$integration->id}", [
            'name' => $integration->name,
            'slug' => $integration->slug,
            'active' => true,
            'default_department_id' => $department->id,
            'default_label_ids' => [],
        ])->assertRedirect();

        $this->assertDatabaseMissing('connected_system_label', [
            'connected_system_id' => $integration->id,
            'label_id' => $label->id,
        ]);
    }
}
