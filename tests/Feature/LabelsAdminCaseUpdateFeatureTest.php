<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsAdminCaseUpdateFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_can_keep_its_own_name_when_updating_other_fields(): void
    {
        $admin = User::factory()->create();
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($admin)
            ->patch("/admin/etiquetas/{$label->id}", ['name' => 'Financeiro', 'color' => '#7c3aed'])
            ->assertRedirect();

        $this->assertDatabaseHas('labels', ['id' => $label->id, 'name' => 'Financeiro', 'color' => '#7c3aed']);
    }
}
