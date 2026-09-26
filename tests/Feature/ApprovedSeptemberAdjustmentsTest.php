<?php

namespace Tests\Feature;

use App\Jobs\SendTicketWhatsAppAutomation;
use App\Models\Department;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Services\TicketWhatsAppAutomations;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovedSeptemberAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(string $name = 'Administrador'): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('admin-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => Role::where('name', 'Super Admin')->value('id'),
            'active' => true,
        ]);
    }

    private function user(string $name = 'Pessoa'): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('user-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'active' => true,
        ]);
    }

    private function ticket(?Department $department = null, ?User $assignee = null, ?User $requester = null): Ticket
    {
        $attributes = [
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket de teste',
            'description' => 'Descrição original',
            'priority' => 'normal',
            'status_id' => Status::system('in_progress')->id,
            'assignee_id' => $assignee?->id,
            'requester_user_id' => $requester?->id,
            'requester_name' => $requester?->name,
            'requester_email' => $requester?->email,
        ];
        if ($department) {
            $attributes['department_id'] = $department->id;
        }

        return Ticket::create($attributes);
    }

    public function test_topbar_is_menu_search_and_bell_without_loose_mobile_brand(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/minha-caixa')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('class="topbar-menu-button"', $html);
        $this->assertStringContainsString('placeholder="Buscar ticket por número, título ou descrição"', $html);
        $this->assertStringContainsString('class="topbar-notifications"', $html);
        $this->assertStringNotContainsString('class="mobile-brand"', $html);

        $menu = strpos($html, 'class="topbar-menu-button"');
        $search = strpos($html, 'class="global-search"');
        $bell = strpos($html, 'class="topbar-notifications"');
        $this->assertLessThan($search, $menu);
        $this->assertLessThan($bell, $search);
    }

    public function test_notification_page_size_is_saved_and_old_read_items_are_deleted_but_unread_never_expire(): void
    {
        $user = $this->admin('Pessoa notificações');

        for ($i = 1; $i <= 35; $i++) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode(['title' => 'Notificação '.$i]),
                'read_at' => null,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs($user)->get('/notificacoes')->assertOk()
            ->assertSee('Mostrando 1 a 20 de 35 notificações')
            ->assertDontSee('pagination.previous')
            ->assertDontSee('pagination.next');
        $this->assertSame(20, substr_count($response->getContent(), 'class="notification-row unread"'));

        $response = $this->actingAs($user)->get('/notificacoes?per_page=10')->assertOk()
            ->assertSee('Mostrando 1 a 10 de 35 notificações');
        $this->assertSame(10, substr_count($response->getContent(), 'class="notification-row unread"'));
        $this->assertSame(10, $user->fresh()->notifications_per_page);

        $this->actingAs($user)->get('/notificacoes')->assertOk()
            ->assertSee('Mostrando 1 a 10 de 35 notificações');

        $oldRead = (string) Str::uuid();
        $oldUnread = (string) Str::uuid();
        DB::table('notifications')->insert([
            [
                'id' => $oldRead, 'type' => 'test', 'notifiable_type' => User::class,
                'notifiable_id' => $user->id, 'data' => '{}',
                'read_at' => now()->subDays(8), 'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(8),
            ],
            [
                'id' => $oldUnread, 'type' => 'test', 'notifiable_type' => User::class,
                'notifiable_id' => $user->id, 'data' => '{}',
                'read_at' => null, 'created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60),
            ],
        ]);

        $this->artisan('tickets:cleanup-notifications')->assertExitCode(0);
        $this->assertDatabaseMissing('notifications', ['id' => $oldRead]);
        $this->assertDatabaseHas('notifications', ['id' => $oldUnread, 'read_at' => null]);
    }

    public function test_triage_is_protected_default_box_allows_only_internal_notes_until_routed(): void
    {
        $admin = $this->admin();
        $wouldBeAssignee = $this->user('Responsável indevido');
        $triage = Department::triage();

        $this->actingAs($admin)->post('/tickets', [
            'title' => 'Ticket de teste',
            'description' => 'Descrição original',
            'priority' => 'normal',
            'assignee_id' => $wouldBeAssignee->id,
        ])->assertRedirect();

        $ticket = Ticket::query()->where('title', 'Ticket de teste')->latest('id')->firstOrFail();

        $this->assertSame($triage->id, $ticket->department_id);
        $this->assertNull($ticket->assignee_id);
        $this->assertTrue($triage->active);
        $this->assertSame('triage', $triage->system_key);

        $response = $this->actingAs($admin)->get('/tickets/'.$ticket->id)->assertOk();
        $response->assertSee('Este ticket está na Triagem.')
            ->assertSee('Salvar departamento e responsável')
            ->assertSee('Nota interna')
            ->assertDontSee('Salvar alterações')
            ->assertDontSee('Resolver ticket');

        $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'internal',
            'body' => 'Nota de triagem.',
        ])->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'ticket_id' => $ticket->id,
            'visibility' => 'internal',
            'body' => 'Nota de triagem.',
        ]);

        $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Não pode.',
        ])->assertForbidden();

        $this->actingAs($admin)->patch('/tickets/'.$ticket->id, [
            'title' => 'Título alterado',
            'description' => 'Mudança',
            'priority' => 'urgent',
            'status_id' => Status::system('new')->id,
        ])->assertRedirect('/tickets/'.$ticket->id);
        $this->assertSame('Ticket de teste', $ticket->fresh()->title);

        $this->actingAs($admin)->get('/admin/departamentos/'.$triage->id.'/editar')
            ->assertForbidden();
    }

    public function test_department_and_optional_responsible_are_changed_together_and_return_to_triage_requires_confirmation(): void
    {
        $admin = $this->admin();
        $triage = Department::triage();
        $department = Department::create(['name' => 'Desenvolvimento', 'active' => true]);
        $target = $this->user('Responsável externo ao departamento');
        $ticket = $this->ticket($triage);

        $this->actingAs($admin)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $department->id,
            'assignee_id' => $target->id,
            'notify_requester' => 0,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($department->id, $ticket->department_id);
        $this->assertSame($target->id, $ticket->assignee_id);
        $this->assertSame('forwarded', $ticket->status->system_key);

        // Ser responsável concede acesso apenas a este ticket, sem criar
        // vínculo com o departamento inteiro.
        $this->assertDatabaseMissing('department_user_access', [
            'user_id' => $target->id,
            'department_id' => $department->id,
        ]);
        $this->actingAs($target)->get('/tickets/'.$ticket->id)->assertOk();

        $this->actingAs($admin)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $triage->id,
            'assignee_id' => $target->id,
            'confirm_triage' => 0,
        ])->assertSessionHasErrors('department_id');
        $this->assertSame($department->id, $ticket->fresh()->department_id);

        $this->actingAs($admin)->patch('/tickets/'.$ticket->id.'/atendimento', [
            'department_id' => $triage->id,
            'assignee_id' => $target->id,
            'confirm_triage' => 1,
            'notify_requester' => 0,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($triage->id, $ticket->department_id);
        $this->assertNull($ticket->assignee_id);
        $this->assertSame('new', $ticket->status->system_key);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'event' => 'triage.returned',
        ]);
    }

    public function test_triage_is_first_and_marked_padrao_in_department_list(): void
    {
        $admin = $this->admin();
        Department::create(['name' => 'Zeta', 'active' => true]);

        $html = $this->actingAs($admin)->get('/departamentos')->assertOk()
            ->assertSee('Padrão')->getContent();

        $this->assertLessThan(strpos($html, 'Zeta'), strpos($html, 'Triagem'));
    }

    public function test_ticket_actions_have_one_shared_requester_checkbox_and_unchecking_suppresses_customer_channels(): void
    {
        Notification::fake();
        Bus::fake();

        $admin = $this->admin();
        $requester = $this->user('Solicitante');
        $department = Department::create(['name' => 'Operações', 'active' => true]);
        $ticket = $this->ticket($department, $admin, $requester);
        $ticket->update(['requester_whatsapp' => '5515999998888']);

        $html = $this->actingAs($admin)->get('/tickets/'.$ticket->id)->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="inline-check ticket-actions-notify"'));
        $this->assertSame(3, substr_count($html, 'name="notify_requester" value="1" data-shared-requester-notify'));

        app(TicketWhatsAppAutomations::class)->save('status', true, 'Status do {numero}: {status}');

        $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/resolver', [
            'notify_requester' => 0,
        ])->assertRedirect();

        Notification::assertNotSentTo($requester, TicketActivityNotification::class);
        Bus::assertNotDispatched(SendTicketWhatsAppAutomation::class);
    }
}
