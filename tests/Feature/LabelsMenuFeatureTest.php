<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsMenuFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_menu_contains_labels_link(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('Etiquetas');
    }
}
