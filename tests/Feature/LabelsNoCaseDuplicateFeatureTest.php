<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsNoCaseDuplicateFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_names_are_unique_ignoring_case(): void
    {
        $admin = User::factory()->create();
        Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $response = $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => 'financeiro', 'color' => '#7c3aed']);

        $response->assertSessionHasErrors('name');
    }
}
