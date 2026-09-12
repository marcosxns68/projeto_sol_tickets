<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsCreateFormFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_ticket_form_lists_available_labels(): void
    {
        $user = User::factory()->create();
        Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)
            ->get('/tickets/create')
            ->assertOk()
            ->assertSee('Financeiro');
    }
}
