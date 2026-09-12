<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsLabelIdValidationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_rejects_unknown_label_id(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);

        $this->actingAs($user)
            ->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => 999999])
            ->assertSessionHasErrors('label_id');
    }
}
