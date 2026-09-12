<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsDeleteUnusedFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_label_can_be_deleted(): void
    {
        $admin = User::factory()->create();
        $label = Label::query()->create(['name' => 'Temporária', 'color' => '#6d28d9']);

        $this->actingAs($admin)->delete("/admin/etiquetas/{$label->id}")->assertRedirect();

        $this->assertDatabaseMissing('labels', ['id' => $label->id]);
    }
}
