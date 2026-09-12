<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsEventFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_changes_are_recorded_in_ticket_history(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $label->id])->assertRedirect();

        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $user->id,
        ]);
    }
}
