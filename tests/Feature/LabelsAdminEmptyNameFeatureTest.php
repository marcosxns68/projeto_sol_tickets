<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsAdminEmptyNameFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_name_is_required(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/etiquetas', ['name' => '', 'color' => '#6d28d9'])
            ->assertSessionHasErrors('name');
    }
}
