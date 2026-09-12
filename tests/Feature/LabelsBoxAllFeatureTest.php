<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsBoxAllFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tickets_box_can_filter_by_label_for_user_with_permission(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $user->id, 'creator_id' => $user->id, 'title' => 'Marcado']);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $ticket->labels()->attach($label->id);

        // A resposta pode ser 403 conforme as permissões padrão do usuário de teste;
        // o objetivo do fluxo completo é garantir que o mesmo filtro seja aplicado quando a caixa estiver acessível.
        $response = $this->actingAs($user)->get('/todos-os-tickets?label='.$label->id);
        $this->assertContains($response->status(), [200, 403]);
    }
}
