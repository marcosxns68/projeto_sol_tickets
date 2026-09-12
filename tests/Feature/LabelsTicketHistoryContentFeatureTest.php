<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsTicketHistoryContentFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_history_mentions_label_name(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $label->id])->assertRedirect();

        $this->assertTrue($ticket->fresh()->events()->get()->contains(function ($event) {
            return str_contains(json_encode($event->toArray(), JSON_UNESCAPED_UNICODE), 'Financeiro');
        }));
    }
}
