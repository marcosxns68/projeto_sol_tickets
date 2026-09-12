<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsTicketEditFormFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_edit_page_lists_and_marks_attached_labels(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $ticket->labels()->attach($label->id);

        $this->actingAs($user)
            ->get("/tickets/{$ticket->id}/edit")
            ->assertOk()
            ->assertSee('Financeiro');
    }
}
