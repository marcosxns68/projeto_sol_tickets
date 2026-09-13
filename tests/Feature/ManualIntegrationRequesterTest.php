<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualIntegrationRequesterTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $role = Role::create(['name' => 'Criador '.uniqid(), 'active' => true]);
        $permission = Permission::firstOrCreate(
            ['key' => 'tickets.create'],
            ['name' => 'Criar tickets', 'group' => 'tickets']
        );
        $role->permissions()->attach($permission->id);

        return User::create([
            'name' => 'Operador',
            'email' => uniqid('operador-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function integration(): ConnectedSystem
    {
        $company = Company::create(['name' => 'Cliente '.uniqid(), 'active' => true]);
        return ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Estúdio França',
            'base_url' => 'https://example.com',
            'active' => true,
        ]);
    }

    public function test_create_form_uses_manual_requester_name_instead_of_external_user_search(): void
    {
        $user = $this->operator();
        $this->integration();

        $this->actingAs($user)
            ->get('/tickets/create')
            ->assertOk()
            ->assertSee('Nome do solicitante')
            ->assertDontSee('Usuário específico')
            ->assertDontSee('Buscar usuário');
    }

    public function test_internal_integration_ticket_saves_manual_requester_without_external_user_link(): void
    {
        $user = $this->operator();
        Status::create([
            'name' => 'Novo',
            'system_key' => 'new',
            'category' => 'open',
            'color' => '#6D28D9',
            'position' => 0,
            'active' => true,
        ]);
        $integration = $this->integration();

        $this->actingAs($user)->post('/tickets', [
            'title' => 'Chamado criado pela equipe',
            'description' => 'Descrição do chamado.',
            'priority' => 'normal',
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'source_mode' => 'integration',
            'system_id' => $integration->id,
            'requester_name' => 'Maria da Silva',
        ])->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame($integration->id, $ticket->system_id);
        $this->assertSame('Maria da Silva', $ticket->requester_name);
        $this->assertNull($ticket->external_requester_id);
        $this->assertNull($ticket->requester_email);
    }

    public function test_ticket_detail_template_displays_integration_and_manual_requester(): void
    {
        $template = file_get_contents(resource_path('views/tickets/show.blade.php'));

        $this->assertStringContainsString('Solicitante', $template);
        $this->assertStringContainsString('$ticket->requester_name', $template);
        $this->assertStringContainsString('$ticket->system', $template);
    }
}
