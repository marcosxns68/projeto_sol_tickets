<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IntegrationSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketCreationV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, array $permissions = ['tickets.create']): User
    {
        $role = Role::create(['name' => $name.' '.uniqid(), 'active' => true]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function access(User $user, Department $department, string $level): void
    {
        DB::table('department_user_access')->insert([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => $level,
            'follow_department' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(Department $department, array $extra = []): array
    {
        return array_merge([
            'source_mode' => 'internal',
            'title' => 'Nova solicitação',
            'description' => 'Descrição completa',
            'priority' => 'normal',
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'department_id' => $department->id,
        ], $extra);
    }

    public function test_create_form_is_simplified_and_lists_only_sendable_departments(): void
    {
        $user = $this->user('Criador');
        $allowed = Department::create(['name' => 'Suporte Permitido', 'active' => true]);
        $blocked = Department::create(['name' => 'Financeiro Bloqueado', 'active' => true]);
        $this->access($user, $allowed, 'send');

        $this->actingAs($user)->get('/tickets/create')
            ->assertOk()
            ->assertSee('Este ticket é para')
            ->assertSee('Minha equipe')
            ->assertSee('Uma empresa/cliente')
            ->assertSee('Suporte Permitido')
            ->assertDontSee('Financeiro Bloqueado')
            ->assertSee('Responsável')
            ->assertSee('Colaboradores')
            ->assertSee('Seguidores')
            ->assertSee('data-user-picker', false);
    }

    public function test_creator_without_another_relation_loses_ticket_visibility_after_creation(): void
    {
        $user = $this->user('Criador Sem Seguir');
        $department = Department::create(['name' => 'Destino', 'active' => true]);
        $this->access($user, $department, 'send');

        $response = $this->actingAs($user)->post('/tickets', $this->payload($department));
        $ticket = Ticket::latest('id')->firstOrFail();

        $response->assertRedirect(route('boxes.mine'));
        $this->assertSame($user->id, $ticket->creator_id);
        $this->actingAs($user)->get(route('tickets.show', $ticket))->assertForbidden();
    }

    public function test_creator_can_search_and_add_self_as_normal_follower(): void
    {
        $user = $this->user('Criador Seguidor');
        $department = Department::create(['name' => 'Destino Seguido', 'active' => true]);
        $this->access($user, $department, 'send');

        $response = $this->actingAs($user)->post('/tickets', $this->payload($department, [
            'follower_ids' => [$user->id],
        ]));
        $ticket = Ticket::latest('id')->firstOrFail();

        $response->assertRedirect(route('tickets.show', $ticket));
        $this->assertDatabaseHas('ticket_participants', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'type' => 'follower',
        ]);
    }

    public function test_cannot_create_ticket_for_department_without_send_access(): void
    {
        $user = $this->user('Criador Restrito');
        $department = Department::create(['name' => 'Sem Destino', 'active' => true]);

        $this->actingAs($user)->post('/tickets', $this->payload($department))
            ->assertForbidden();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_integration_default_department_still_requires_send_access(): void
    {
        $user = $this->user('Criador Integração Restrito');
        $department = Department::create(['name' => 'Destino padrão restrito', 'active' => true]);
        $company = Company::create(['name' => 'Empresa Restrita', 'active' => true]);
        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Sistema Restrito',
            'base_url' => 'https://93.184.216.34',
            'active' => true,
        ]);
        app(IntegrationSettings::class)->put($integration->id, 'department_id', $department->id);

        $this->actingAs($user)->post('/tickets', $this->payload($department, [
            'source_mode' => 'integration',
            'system_id' => $integration->id,
            'integration_target' => 'integration',
        ]))->assertForbidden();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_manual_company_requester_email_is_linked_to_exact_external_user(): void
    {
        $user = $this->user('Criador Empresa');
        $department = Department::create(['name' => 'Suporte Cliente', 'active' => true]);
        $this->access($user, $department, 'send');
        $company = Company::create(['name' => 'Empresa Teste', 'active' => true]);
        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Sistema Cliente',
            'base_url' => 'https://93.184.216.34',
            'webhook_url' => 'https://93.184.216.34/webhook',
            'active' => true,
        ]);
        $integration->issueWebhookSecret();

        Http::fake(['*' => Http::response(['data' => [[
            'id' => 'cliente-42',
            'name' => 'Maria Cliente',
            'email' => 'maria@cliente.test',
        ]]], 200)]);

        $this->actingAs($user)->post('/tickets', $this->payload($department, [
            'source_mode' => 'integration',
            'system_id' => $integration->id,
            'requester_name' => 'Maria Cliente',
            'requester_email' => 'maria@cliente.test',
        ]))->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame('cliente-42', $ticket->external_requester_id);
        $this->assertSame('Maria Cliente', $ticket->requester_name);
        $this->assertSame('maria@cliente.test', $ticket->requester_email);
    }
}
