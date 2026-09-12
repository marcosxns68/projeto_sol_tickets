<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsTrimNameFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_name_is_trimmed_before_saving(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => '  Financeiro  ', 'color' => '#6d28d9'])
            ->assertRedirect();

        $this->assertDatabaseHas('labels', ['name' => 'Financeiro']);
    }
}
