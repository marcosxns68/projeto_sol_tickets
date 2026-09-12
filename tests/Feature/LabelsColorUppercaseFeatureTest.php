<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsColorUppercaseFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_color_accepts_uppercase_hex_digits(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => 'Financeiro', 'color' => '#ABCDEF'])
            ->assertSessionDoesntHaveErrors('color');
    }
}
