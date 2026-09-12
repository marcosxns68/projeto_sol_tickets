<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsOrderingFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_management_page_orders_labels_by_name(): void
    {
        $admin = User::factory()->create();
        Label::query()->create(['name' => 'Urgente', 'color' => '#dc2626']);
        Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $response = $this->actingAs($admin)->get('/admin/etiquetas');
        $response->assertOk();
        $this->assertTrue(strpos($response->getContent(), 'Financeiro') < strpos($response->getContent(), 'Urgente'));
    }
}
