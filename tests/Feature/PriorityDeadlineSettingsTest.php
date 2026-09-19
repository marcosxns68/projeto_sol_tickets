<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Company;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PriorityDeadlines;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PriorityDeadlineSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin de prazos',
            'email' => 'admin.prazos@sutoorii.com',
            'password' => 'Teste123456',
            'email_verified_at' => now(),
            'active' => true,
            'role_id' => Role::where('name', 'Super Admin')->value('id'),
        ]);
    }

    public function test_default_deadlines_are_calendar_days_with_end_of_day_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 14:15:00', 'America/Sao_Paulo'));
        try {
            $service = app(PriorityDeadlines::class);
            $this->assertSame('29/09/2026', $service->dueAt('low')->format('d/m/Y'));
            $this->assertSame('26/09/2026', $service->dueAt('normal')->format('d/m/Y'));
            $this->assertSame('21/09/2026', $service->dueAt('high')->format('d/m/Y'));
            $this->assertSame('19/09/2026', $service->dueAt('urgent')->format('d/m/Y'));
            $this->assertSame('23:59', $service->dueAt('urgent')->format('H:i'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_only_super_admin_may_configure_priority_days_and_new_integration_tickets_receive_the_configured_deadline(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch('/admin/configuracoes/prioridades', [
            'low' => 11, 'normal' => 8, 'high' => 3, 'urgent' => 0,
        ])->assertRedirect();

        $this->assertSame(8, app(PriorityDeadlines::class)->days('normal'));

        $regular = User::create([
            'name' => 'Equipe', 'email' => 'equipe@sutoorii.com',
            'password' => 'Teste123456', 'email_verified_at' => now(), 'active' => true,
        ]);
        $this->actingAs($regular)->patch('/admin/configuracoes/prioridades', [
            'low' => 1, 'normal' => 1, 'high' => 1, 'urgent' => 0,
        ])->assertForbidden();

        $company = Company::create(['name' => 'Cliente Externo', 'active' => true]);
        $integration = ConnectedSystem::create(['name' => 'Estúdio França', 'company_id' => $company->id, 'active' => true]);
        $integration->forceFill(['api_token_hash' => hash('sha256', 'token-prioridade')])->save();

        $created = $this->withHeaders([
            'Authorization' => 'Bearer token-prioridade',
            'X-External-User-Id' => '153',
            'X-External-User-Role' => 'user',
        ])->postJson('/api/v1/tickets', [
            'requester_name' => 'Solicitante', 'requester_email' => 'solicitante@example.test',
            'title' => 'Dúvida', 'description' => 'Solicitação', 'priority' => 'normal',
        ]);

        $created->assertCreated()
            ->assertJsonPath('ticket.priority', 'normal');

        $ticket = Ticket::where('system_id', $integration->id)->firstOrFail();
        $this->assertSame(now('America/Sao_Paulo')->addDays(8)->format('d/m/Y'), $ticket->due_at->format('d/m/Y'));
        $this->assertSame($ticket->due_at->format('d/m/Y'), Carbon::parse($created->json('ticket.due_at'))->format('d/m/Y'));
    }
}
