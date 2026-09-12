<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsSearchFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_are_eager_loaded_in_ticket_box(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id, 'title' => 'Teste']);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $ticket->labels()->attach($label->id);

        $response = $this->actingAs($user)->get('/minha-caixa');

        $response->assertOk()->assertSee('Financeiro');
    }
}
