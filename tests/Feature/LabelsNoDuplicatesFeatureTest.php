<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsNoDuplicatesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaching_same_label_twice_does_not_duplicate_pivot(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $label->id])->assertRedirect();
        $this->actingAs($user)->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $label->id])->assertRedirect();

        $this->assertSame(1, $ticket->fresh()->labels()->whereKey($label->id)->count());
    }
}
