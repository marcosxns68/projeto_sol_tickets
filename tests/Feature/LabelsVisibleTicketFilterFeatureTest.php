<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsVisibleTicketFilterFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_filter_respects_ticket_visibility(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $visible = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id, 'title' => 'Visível']);
        $hidden = Ticket::factory()->create(['assignee_id' => $other->id, 'creator_id' => $other->id, 'title' => 'Oculto']);
        $visible->labels()->attach($label->id);
        $hidden->labels()->attach($label->id);

        $this->actingAs($user)
            ->get('/minha-caixa?label='.$label->id)
            ->assertSee('Visível')
            ->assertDontSee('Oculto');
    }
}
