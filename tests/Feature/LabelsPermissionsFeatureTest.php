<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsPermissionsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_change_labels_on_invisible_ticket(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $owner->id, 'creator_id' => $owner->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)
            ->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $label->id])
            ->assertForbidden();
    }
}
