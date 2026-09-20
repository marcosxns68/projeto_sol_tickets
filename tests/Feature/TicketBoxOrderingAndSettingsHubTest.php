<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketBoxOrderingAndSettingsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(array $permissionKeys, string $roleName = 'Operador testes'): User
    {
        $role = $roleName === 'Super Admin'
            ? Role::where('name', 'Super Admin')->firstOrFail()
            : Role::create(['name' => $roleName.uniqid(), 'active' => true]);
        if ($roleName !== 'Super Admin') {
            $role->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id'));
        }

        return User::create([
            'name' => 'Operador de teste',
            'email' => uniqid('caixa-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function ticket(Department $department, User $assignee, string $title, ?string $dueAt, string $priority = 'normal', int $ageInDays = 0): Ticket
    {
        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => $title,
            'description' => 'Descrição',
            'priority' => $priority,
            'status_id' => Status::system('new')->id,
            'assignee_id' => $assignee->id,
            'department_id' => $department->id,
            'due_at' => $dueAt,
        ]);

        if ($ageInDays > 0) {
            DB::table('tickets')->where('id', $ticket->id)
                ->update(['created_at' => now()->subDays($ageInDays)]);
        }

        return $ticket;
    }

    private function orderedTitles($response): array
    {
        return $response->viewData('tickets')->getCollection()->pluck('title')->all();
    }

    public function test_my_box_defaults_to_earliest_deadline_overdue_first_without_deadline_last(): void
    {
        $operator = $this->user(['tickets.view_department']);
        $department = Department::create(['name' => 'Ordenação', 'active' => true]);
        $noDeadline = $this->ticket($department, $operator, 'Sem prazo', null, 'urgent', 10);
        $farAway = $this->ticket($department, $operator, 'Futuro distante', now()->addDays(8)->toDateTimeString(), 'urgent');
        $soon = $this->ticket($department, $operator, 'Próximo prazo', now()->addDay()->toDateTimeString(), 'normal');
        $overdue = $this->ticket($department, $operator, 'Atrasado', now()->subHour()->toDateTimeString(), 'low');

        $response = $this->actingAs($operator)->get('/minha-caixa')->assertOk();
        $this->assertSame(
            ['Atrasado', 'Próximo prazo', 'Futuro distante', 'Sem prazo'],
            $this->orderedTitles($response)
        );
        $response->assertSee('Prazo: mais próximo')
            ->assertSee('Idade: mais antigos')
            ->assertSee('Idade: mais recentes')
            ->assertSee('ticket-col-center')
            ->assertSee('ticket-deadline-time');
    }

    public function test_my_box_can_order_by_age_and_preserves_filters_and_pagination(): void
    {
        $operator = $this->user(['tickets.view_department']);
        $department = Department::create(['name' => 'Tempo', 'active' => true]);
        $this->ticket($department, $operator, 'Mais novo', now()->subDay()->toDateTimeString(), 'normal');
        $this->ticket($department, $operator, 'Mais antigo', now()->addDays(3)->toDateTimeString(), 'normal', 15);
        $this->ticket($department, $operator, 'Intermediário', now()->addDays(2)->toDateTimeString(), 'normal', 6);

        $this->assertSame(
            ['Mais antigo', 'Intermediário', 'Mais novo'],
            $this->orderedTitles($this->actingAs($operator)->get('/minha-caixa?sort=oldest')->assertOk())
        );
        $this->assertSame(
            ['Mais novo', 'Intermediário', 'Mais antigo'],
            $this->orderedTitles($this->actingAs($operator)->get('/minha-caixa?sort=newest')->assertOk())
        );
        $this->assertSame(
            ['Mais novo', 'Intermediário', 'Mais antigo'],
            $this->orderedTitles($this->actingAs($operator)->get('/minha-caixa?sort=due_soon&priority=normal')->assertOk())
        );
    }

    public function test_all_tickets_orders_before_pagination_and_never_exposes_unauthorized_access(): void
    {
        $manager = $this->user(['tickets.view_all']);
        $regular = $this->user([]);
        $department = Department::create(['name' => 'Prioridades', 'active' => true]);

        $this->actingAs($regular)->get('/todos-os-tickets?sort=oldest')->assertForbidden();

        for ($i = 0; $i < 22; $i++) {
            $this->ticket(
                $department,
                $manager,
                sprintf('Ticket %02d', $i),
                now()->addDays($i + 1)->toDateTimeString(),
                'normal',
                $i + 1
            );
        }

        $first = $this->actingAs($manager)->get('/todos-os-tickets?sort=due_soon&page=1')->assertOk();
        $second = $this->actingAs($manager)->get('/todos-os-tickets?sort=due_soon&page=2')->assertOk();

        $this->assertCount(20, $first->viewData('tickets')->items());
        $this->assertSame('Ticket 00', $this->orderedTitles($first)[0]);
        $this->assertSame(['Ticket 20', 'Ticket 21'], $this->orderedTitles($second));
        $second->assertSee('sort=due_soon', false);
    }

    public function test_settings_hub_replaces_sidebar_items_without_removing_notification_bell(): void
    {
        $admin = $this->user([], 'Super Admin');
        $mailAndIntegrations = $this->user(
            ['users.manage', 'permissions.manage', 'integrations.manage'],
            'Integrações e correio'
        );
        $onlyIntegrations = $this->user(['integrations.manage'], 'Integrações');
        $regular = $this->user([], 'Sem acesso');

        $this->actingAs($admin)->get('/admin/configuracoes')
            ->assertOk()
            ->assertSee('Prazos por prioridade')
            ->assertSee('WhatsApp')
            ->assertSee('Configurações de notificações');

        $this->actingAs($mailAndIntegrations)->get('/admin/configuracoes')
            ->assertOk()
            ->assertSee('Configurações de e-mail')
            ->assertSee('Integrações')
            ->assertDontSee('Prazos por prioridade');

        $this->actingAs($onlyIntegrations)->get('/admin/configuracoes')
            ->assertOk()
            ->assertSee('Integrações')
            ->assertDontSee('Configurações de e-mail')
            ->assertDontSee('Prazos por prioridade');

        $this->actingAs($regular)->get('/admin/configuracoes')->assertForbidden();

        $page = $this->actingAs($admin)->get('/minha-caixa')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('admin.settings.index').'"', $page);
        $this->assertStringContainsString('href="'.route('notifications.index').'"', $page);
        $this->assertStringContainsString('data-notifications-topbar', $page);
        $this->assertStringNotContainsString('class="sidebar-link "><span>Notificações</span>', $page);
        $this->assertStringContainsString('css/boxes-ordering.css?v=', $page);
    }
}
