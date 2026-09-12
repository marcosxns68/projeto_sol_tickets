<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsNoDeleteUsedLabelFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_used_label_does_not_delete_ticket(): void
    {
        $admin = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $admin->id, 'creator_id' => $admin->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $ticket->labels()->attach($label->id);

        $this->actingAs($admin)->delete("/admin/etiquetas/{$label->id}")->assertRedirect();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }
}
