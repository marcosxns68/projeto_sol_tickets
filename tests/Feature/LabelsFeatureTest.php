<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LabelsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $keys): User
    {
        $role = Role::create(['name' => 'Etiquetas '.uniqid(), 'active' => true]);
        $permissionIds = Permission::query()->whereIn('key', $keys)->pluck('id');
        $role->permissions()->attach($permissionIds);

        return User::create([
            'name' => 'Usuário Etiquetas',
            'email' => uniqid('labels-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function status(): Status
    {
        return Status::system('new') ?? Status::create([
            'name' => 'Novo',
            'system_key' => 'new',
            'category' => 'open',
            'color' => '#6D28D9',
            'position' => 0,
            'active' => true,
        ]);
    }

    private function ticket(User $user, string $title): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => $title,
            'description' => 'Descrição do teste',
            'priority' => 'normal',
            'status_id' => $this->status()->id,
            'creator_id' => $user->id,
            'assignee_id' => $user->id,
            'due_at' => now()->addDay(),
        ]);
    }

    public function test_label_manager_can_create_update_and_delete_labels(): void
    {
        $admin = $this->userWithPermissions(['labels.manage']);

        $this->actingAs($admin)
            ->get('/admin/etiquetas')
            ->assertOk()
            ->assertSee('Etiquetas');

        $this->actingAs($admin)->post('/admin/etiquetas', [
            'name' => 'Financeiro',
            'color' => '#6d28d9',
        ])->assertRedirect('/admin/etiquetas');

        $label = Label::query()->where('name', 'Financeiro')->firstOrFail();

        $this->actingAs($admin)->patch('/admin/etiquetas/'.$label->id, [
            'name' => 'Cobrança',
            'color' => '#7c3aed',
        ])->assertRedirect('/admin/etiquetas');

        $this->assertDatabaseHas('labels', [
            'id' => $label->id,
            'name' => 'Cobrança',
            'color' => '#7c3aed',
        ]);

        $this->actingAs($admin)
            ->delete('/admin/etiquetas/'.$label->id)
            ->assertRedirect('/admin/etiquetas');

        $this->assertDatabaseMissing('labels', ['id' => $label->id]);
    }

    public function test_user_without_label_permission_cannot_manage_label_catalog(): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)
            ->get('/admin/etiquetas')
            ->assertForbidden();
    }

    public function test_authorized_user_can_attach_and_remove_existing_label_from_visible_ticket(): void
    {
        $user = $this->userWithPermissions(['tickets.manage_labels']);
        $ticket = $this->ticket($user, 'Chamado etiquetado');
        $label = Label::create(['name' => 'Financeiro', 'color' => '#6d28d9']);

        $this->actingAs($user)
            ->post('/tickets/'.$ticket->id.'/etiquetas', ['label_id' => $label->id])
            ->assertRedirect('/tickets/'.$ticket->id);

        $this->assertDatabaseHas('label_ticket', [
            'ticket_id' => $ticket->id,
            'label_id' => $label->id,
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $user->id,
            'event' => 'label.added',
        ]);

        $this->actingAs($user)
            ->delete('/tickets/'.$ticket->id.'/etiquetas/'.$label->id)
            ->assertRedirect('/tickets/'.$ticket->id);

        $this->assertDatabaseMissing('label_ticket', [
            'ticket_id' => $ticket->id,
            'label_id' => $label->id,
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $user->id,
            'event' => 'label.removed',
        ]);
    }

    public function test_ticket_boxes_display_and_filter_by_label(): void
    {
        $user = $this->userWithPermissions([]);
        $label = Label::create(['name' => 'Financeiro', 'color' => '#6d28d9']);
        $matching = $this->ticket($user, 'Cobrança cliente');
        $other = $this->ticket($user, 'Acesso ao sistema');
        $matching->labels()->attach($label->id);

        $response = $this->actingAs($user)->get('/minha-caixa?label='.$label->id);

        $response->assertOk()
            ->assertSee('Financeiro')
            ->assertSee('Cobrança cliente')
            ->assertDontSee('Acesso ao sistema');
    }

    public function test_integration_can_store_default_labels_and_apply_them_to_new_api_ticket(): void
    {
        $admin = $this->userWithPermissions(['integrations.manage']);
        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $label = Label::create(['name' => 'Estúdio França', 'color' => '#6d28d9']);
        $company = Company::create(['name' => 'Integrações '.uniqid(), 'active' => false]);
        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Estúdio França',
            'active' => true,
        ]);
        $integration->forceFill(['api_token_hash' => hash('sha256', 'token-franca')])->save();

        $this->actingAs($admin)->patch('/admin/integracoes/'.$integration->id, [
            'name' => 'Estúdio França',
            'department_id' => $department->id,
            'default_label_ids' => [$label->id],
            'active' => '1',
        ])->assertRedirect('/admin/integracoes');

        $stored = DB::table('settings')
            ->where('key', 'integration.'.$integration->id.'.default_label_ids')
            ->value('value');
        $this->assertSame([$label->id], json_decode((string) $stored, true));

        $this->withHeaders([
            'Authorization' => 'Bearer token-franca',
            'X-External-User-Id' => '153',
            'X-External-User-Role' => 'user',
        ])->postJson('/api/v1/tickets', [
            'requester_name' => 'Cliente Teste',
            'requester_email' => 'cliente@example.com',
            'title' => 'Erro de acesso',
            'description' => 'Cliente não consegue entrar.',
            'external_reference' => 'labels-default-1',
        ])->assertCreated()
            ->assertJsonPath('ticket.labels.0.name', 'Estúdio França');

        $ticket = Ticket::query()->where('external_reference', 'labels-default-1')->firstOrFail();
        $this->assertTrue($ticket->labels()->whereKey($label->id)->exists());
    }
}
