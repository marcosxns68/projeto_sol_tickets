<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsValidationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_name_must_be_unique(): void
    {
        $admin = User::factory()->create();
        Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => 'Financeiro', 'color' => '#7c3aed'])
            ->assertSessionHasErrors('name');
    }

    public function test_label_color_must_be_hexadecimal(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => 'Financeiro', 'color' => 'roxo'])
            ->assertSessionHasErrors('color');
    }
}
