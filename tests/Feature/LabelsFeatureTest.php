<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_labels_management_page(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/etiquetas');

        $response->assertOk();
        $response->assertSee('Etiquetas');
    }

    public function test_authorized_user_can_attach_and_remove_label_from_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['creator_id' => $user->id, 'assignee_id' => $user->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)
            ->post("/tickets/{$ticket->id}/etiquetas", ['label_id' => $label->id])
            ->assertRedirect();

        $this->assertDatabaseHas('label_ticket', ['ticket_id' => $ticket->id, 'label_id' => $label->id]);

        $this->actingAs($user)
            ->delete("/tickets/{$ticket->id}/etiquetas/{$label->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('label_ticket', ['ticket_id' => $ticket->id, 'label_id' => $label->id]);
    }

    public function test_ticket_box_can_filter_by_label(): void
    {
        $user = User::factory()->create();
        $matching = Ticket::factory()->create(['assignee_id' => $user->id, 'title' => 'Cobrança cliente']);
        $other = Ticket::factory()->create(['assignee_id' => $user->id, 'title' => 'Acesso sistema']);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $matching->labels()->attach($label->id);

        $response = $this->actingAs($user)->get('/minha-caixa?label='.$label->id);

        $response->assertOk();
        $response->assertSee('Cobrança cliente');
        $response->assertDontSee('Acesso sistema');
    }
}
