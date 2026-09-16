<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationTicketPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_integration_create_permission_does_not_see_integration_destination(): void
    {
        $user = $this->ticketCreator(false);
        $this->integration();

        $this->actingAs($user)
            ->get('/tickets/create')
            ->assertOk()
            ->assertDontSee('Tipo de chamado')
            ->assertDontSee('Empresa / cliente')
            ->assertDontSee('Empresa / sistema integrado')
            ->assertDontSee('Chamado geral')
            ->assertDontSee('Usuário específico');
    }

    public function test_user_without_integration_create_permission_cannot_create_integration_ticket_by_direct_request(): void
    {
        $user = $this->ticketCreator(false);
        $integration = $this->integration();
        $this->initialStatus();

        $this->actingAs($user)
            ->post('/tickets', $this->integrationPayload($integration))
            ->assertForbidden();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_user_with_integration_permission_sees_progressive_type_and_external_sections(): void
    {
        $user = $this->ticketCreator(true);
        $this->integration();

        $this->actingAs($user)
            ->get('/tickets/create')
            ->assertOk()
            ->assertSee('Tipo de chamado')
            ->assertSee('Minha equipe')
            ->assertSee('Empresa / cliente')
            ->assertSee('data-create-progressive', false)
            ->assertSee('data-integration-section', false)
            ->assertSee('data-general-requester', false)
            ->assertSee('data-external-requester', false)
            ->assertSee('Atribuição inicial (opcional)');
    }

    public function test_user_with_integration_create_permission_can_create_integration_ticket(): void
    {
        $user = $this->ticketCreator(true);
        $integration = $this->integration();
        $this->initialStatus();

        $this->actingAs($user)
            ->post('/tickets', $this->integrationPayload($integration))
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'system_id' => $integration->id,
            'title' => 'Ticket autorizado para integração',
        ]);
    }

    public function test_user_without_integration_create_permission_cannot_search_external_users(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $user = $this->ticketCreator(false);
        $integration = $this->integration();

        $this->actingAs($user)
            ->getJson('/integracoes/'.$integration->id.'/usuarios?search=maria')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_permission_editor_renders_yes_no_as_button_choices_instead_of_select(): void
    {
        $manageUsers = Permission::firstOrCreate(
            ['key' => 'users.manage'],
            ['name' => 'Gerenciar usuários', 'group' => 'users']
        );
        $managePermissions = Permission::firstOrCreate(
            ['key' => 'permissions.manage'],
            ['name' => 'Gerenciar permissões', 'group' => 'permissions']
        );
        $integrationPermission = Permission::firstOrCreate(
            ['key' => 'tickets.create_integration'],
            ['name' => 'Criar tickets para integrações', 'group' => 'tickets']
        );

        $adminRole = Role::create(['name' => 'Administrador']);
        $adminRole->permissions()->attach([$manageUsers->id, $managePermissions->id]);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-permissoes@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $adminRole->id,
            'active' => true,
        ]);

        $target = User::create([
            'name' => 'Operador',
            'email' => 'operador-permissoes@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->get('/admin/usuarios/'.$target->id.'/editar');

        $response->assertOk()
            ->assertSee('Criar tickets para integrações')
            ->assertSee('permission-choice', false)
            ->assertSee('type="radio"', false)
            ->assertSee('value="yes"', false)
            ->assertSee('value="no"', false)
            ->assertDontSee('<select name="permissions['.$integrationPermission->id.']"', false);
    }

    private function ticketCreator(bool $canCreateForIntegration): User
    {
        $create = Permission::firstOrCreate(
            ['key' => 'tickets.create'],
            ['name' => 'Criar tickets', 'group' => 'tickets']
        );
        $createIntegration = Permission::firstOrCreate(
            ['key' => 'tickets.create_integration'],
            ['name' => 'Criar tickets para integrações', 'group' => 'tickets']
        );

        $role = Role::create(['name' => 'Criador '.uniqid(), 'active' => true]);
        $permissionIds = [$create->id];
        if ($canCreateForIntegration) {
            $permissionIds[] = $createIntegration->id;
        }
        $role->permissions()->attach($permissionIds);

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
        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Estúdio França',
            'base_url' => 'https://93.184.216.34',
            'webhook_url' => 'https://93.184.216.34/webhook',
            'active' => true,
        ]);
        $integration->issueWebhookSecret();

        return $integration;
    }

    private function initialStatus(): void
    {
        Status::create([
            'name' => 'Novo',
            'system_key' => 'new',
            'category' => 'open',
            'color' => '#6D28D9',
            'position' => 0,
            'active' => true,
        ]);
    }

    private function integrationPayload(ConnectedSystem $integration): array
    {
        return [
            'title' => 'Ticket autorizado para integração',
            'description' => 'Descrição do chamado.',
            'priority' => 'normal',
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'source_mode' => 'integration',
            'system_id' => $integration->id,
            'integration_target' => 'integration',
        ];
    }
}
