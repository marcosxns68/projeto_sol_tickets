<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsDefaultLabelRemovalFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_label_detaches_it_from_integration_defaults(): void
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

        $this->actingAs($admin)->delete("/admin/etiquetas/{$label->id}")->assertRedirect();

        $this->assertDatabaseMissing('connected_system_label', ['label_id' => $label->id]);
    }
}
