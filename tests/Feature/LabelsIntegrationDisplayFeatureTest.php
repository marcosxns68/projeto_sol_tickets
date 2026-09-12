<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsIntegrationDisplayFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_admin_page_displays_default_labels(): void
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
        $integration->defaultLabels()->attach($label->id);

        $this->actingAs($admin)
            ->get('/admin/integracoes')
            ->assertOk()
            ->assertSee('Estúdio França');
    }
}
