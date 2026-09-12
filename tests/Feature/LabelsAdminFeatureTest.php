<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsAdminFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_label(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => 'Financeiro', 'color' => '#6d28d9'])
            ->assertRedirect();

        $label = Label::query()->where('name', 'Financeiro')->firstOrFail();

        $this->actingAs($admin)
            ->patch("/admin/etiquetas/{$label->id}", ['name' => 'Cobrança', 'color' => '#7c3aed'])
            ->assertRedirect();

        $this->assertDatabaseHas('labels', ['id' => $label->id, 'name' => 'Cobrança', 'color' => '#7c3aed']);

        $this->actingAs($admin)
            ->delete("/admin/etiquetas/{$label->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('labels', ['id' => $label->id]);
    }
}
