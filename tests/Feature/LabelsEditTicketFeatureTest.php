<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsEditTicketFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_labels_can_be_replaced_when_editing_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
        $old = Label::query()->create(['name' => 'Antiga', 'color' => '#6d28d9']);
        $new = Label::query()->create(['name' => 'Nova', 'color' => '#7c3aed']);
        $ticket->labels()->attach($old->id);

        $this->actingAs($user)->put("/tickets/{$ticket->id}", [
            'title' => $ticket->title,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'label_ids' => [$new->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('label_ticket', ['ticket_id' => $ticket->id, 'label_id' => $old->id]);
        $this->assertDatabaseHas('label_ticket', ['ticket_id' => $ticket->id, 'label_id' => $new->id]);
    }
}
