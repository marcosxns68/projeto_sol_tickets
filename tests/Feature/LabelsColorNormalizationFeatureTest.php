<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsColorNormalizationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_color_accepts_lowercase_hex(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => 'Financeiro', 'color' => '#6d28d9'])
            ->assertSessionDoesntHaveErrors('color');

        $this->assertDatabaseHas('labels', ['name' => 'Financeiro', 'color' => '#6d28d9']);
    }
}
