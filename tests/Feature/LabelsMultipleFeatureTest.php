<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsMultipleFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_supports_multiple_labels(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
        $first = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $second = Label::query()->create(['name' => 'Urgente', 'color' => '#dc2626']);

        $this->actingAs($user)->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $first->id])->assertRedirect();
        $this->actingAs($user)->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $second->id])->assertRedirect();

        $this->assertSame(2, $ticket->fresh()->labels()->count());
    }
}
