<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsFilterOptionsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_box_shows_available_labels_in_filter(): void
    {
        $user = User::factory()->create();
        Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)
            ->get('/minha-caixa')
            ->assertOk()
            ->assertSee('Financeiro');
    }
}
