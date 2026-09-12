<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsIntegrationDefaultsVisibleFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrations_page_lists_available_labels_for_defaults(): void
    {
        $admin = User::factory()->create();
        Department::query()->create(['name' => 'Suporte']);
        Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($admin)
            ->get('/admin/integracoes')
            ->assertOk()
            ->assertSee('Financeiro');
    }
}
