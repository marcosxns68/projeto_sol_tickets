<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsAdminUsageCountFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_management_page_can_show_usage_count(): void
    {
        $admin = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $admin->id, 'creator_id' => $admin->id]);
        $label = Label::query()->create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $ticket->labels()->attach($label->id);

        $this->actingAs($admin)
            ->get('/admin/etiquetas')
            ->assertOk()
            ->assertSee('1');
    }
}
