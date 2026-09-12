<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsCreateTicketFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_can_be_created_with_labels(): void
    {
        $user = User::factory()->create();
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $response = $this->actingAs($user)->post('/tickets', [
            'title' => 'Teste com etiqueta',
            'description' => 'Chamado criado com uma etiqueta.',
            'priority' => 'normal',
            'label_ids' => [$label->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('label_ticket', ['label_id' => $label->id]);
    }
}
