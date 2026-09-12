<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsIntegrationAdminFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_default_labels_to_integration(): void
    {
        $admin = User::factory()->create();
        $department = Department::query()->create(['name' => 'Suporte']);
        $integration = ConnectedSystem::query()->create([
            'name' => 'Estúdio França',
            'slug' => 'estudio-franca',
            'api_key_hash' => hash('sha256', 'test-key'),
            'active' => true,
            'default_department_id' => $department->id,
        ]);
        $label = Label::query()->create(['name' => 'Estúdio França', 'color' => '#6d28d9']);

        $this->actingAs($admin)
            ->patch("/admin/integracoes/{$integration->id}", [
                'name' => $integration->name,
                'slug' => $integration->slug,
                'active' => true,
                'default_department_id' => $department->id,
                'default_label_ids' => [$label->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('connected_system_label', [
            'connected_system_id' => $integration->id,
            'label_id' => $label->id,
        ]);
    }
}
