<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsFilterPersistenceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_filter_keeps_query_string_in_pagination(): void
    {
        $user = User::factory()->create();
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        for ($i = 0; $i < 21; $i++) {
            $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
            $ticket->labels()->attach($label->id);
        }

        $this->actingAs($user)
            ->get('/minha-caixa?label='.$label->id)
            ->assertOk()
            ->assertSee('label='.$label->id, false);
    }
}
