<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsAdminDeleteUiFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_management_page_contains_delete_controls(): void
    {
        $admin = User::factory()->create();
        Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($admin)
            ->get('/admin/etiquetas')
            ->assertOk()
            ->assertSee('Excluir');
    }
}
