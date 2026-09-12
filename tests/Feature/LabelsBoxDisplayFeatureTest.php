<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsBoxDisplayFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_box_displays_attached_labels(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id, 'title' => 'Chamado etiquetado']);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $ticket->labels()->attach($label->id);

        $this->actingAs($user)
            ->get('/minha-caixa')
            ->assertOk()
            ->assertSee('Chamado etiquetado')
            ->assertSee('Financeiro');
    }
}
