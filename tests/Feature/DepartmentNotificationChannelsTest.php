<?php

namespace Tests\Feature;

use App\Jobs\SendDepartmentWhatsApp;
use App\Models\Department;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Services\TicketNotifier;
use App\Services\WhatsAppConnection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DepartmentNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('dept-').'@sutoorii.test',
            'password' => 'SenhaTeste123!',
            'email_verified_at' => now(),
            'active' => true,
        ]);
    }

    private function subscribe(User $user, Department $department): void
    {
        DB::table('department_user_access')->insert([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => 'view',
            'follow_department' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ticket(Department $department): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'title' => 'Erro no sistema',
            'description' => 'Descrição da abertura',
            'priority' => 'normal',
            'origin' => 'internal',
            'status_id' => Status::system('new')->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_each_department_channel_can_be_selected_independently_and_read_markers_are_private(): void
    {
        $user = $this->user('Seguidor');
        $other = $this->user('Outro usuário');
        $department = Department::create(['name' => 'Desenvolvimento', 'active' => true]);
        $this->subscribe($user, $department);
        $this->subscribe($other, $department);
        $user->update(['whatsapp' => '5515999998888']);
        $user->refresh();

        $this->actingAs($user)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 0, 'notify_whatsapp' => 1, 'notify_push' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('department_user_access', [
            'user_id' => $user->id, 'department_id' => $department->id,
            'follow_department' => true, 'notify_email' => false,
            'notify_whatsapp' => true, 'notify_push' => false,
        ]);
        $row = DB::table('department_user_access')
            ->where('user_id', $user->id)->where('department_id', $department->id)->first();
        $this->assertNotNull($row->last_seen_at);

        $this->actingAs($other)->post('/departamentos/'.$department->id.'/marcar-vistos')
            ->assertForbidden();

        // Um ticket criado depois de seguir aparece como novidade; o visitante
        // só o considera visto quando clicar explicitamente na opção.
        $this->travel(2)->seconds();
        $ticket = $this->ticket($department);
        $this->actingAs($user)->get('/departamentos')->assertOk()->assertSee('1 novidade');
        $this->actingAs($user)->get('/departamentos/'.$department->id.'/tickets')
            ->assertOk()->assertSee('Novo na caixa');

        $this->actingAs($user)->post('/departamentos/'.$department->id.'/marcar-vistos')
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($user)->get('/departamentos')->assertOk()->assertDontSee('1 novidade');

        $this->actingAs($user)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 0, 'notify_whatsapp' => 0, 'notify_push' => 0,
        ])->assertRedirect();
        $this->assertDatabaseHas('department_user_access', [
            'user_id' => $user->id, 'department_id' => $department->id,
            'follow_department' => false,
        ]);
    }

    public function test_new_ticket_alert_is_specific_to_followed_department_and_channels(): void
    {
        Notification::fake();
        Bus::fake();
        $department = Department::create(['name' => 'Incidentes', 'active' => true]);
        $otherDepartment = Department::create(['name' => 'Financeiro', 'active' => true]);
        $emailUser = $this->user('E-mail');
        $pushUser = $this->user('Push');
        $waUser = $this->user('WhatsApp');
        $unrelated = $this->user('Outro departamento');
        foreach ([$emailUser, $pushUser, $waUser] as $user) {
            $this->subscribe($user, $department);
        }
        $this->subscribe($unrelated, $otherDepartment);
        $waUser->update(['whatsapp' => '5515999998888']);
        $waUser->refresh();

        foreach ([
            [$emailUser, 1, 0, 0],
            [$pushUser, 0, 0, 1],
            [$waUser, 0, 1, 0],
        ] as [$user, $email, $whatsapp, $push]) {
            $this->actingAs($user)->patch('/departamentos/'.$department->id.'/acompanhar', [
                'notify_email' => $email, 'notify_whatsapp' => $whatsapp, 'notify_push' => $push,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $ticket = $this->ticket($department);
        app(TicketNotifier::class)->opened($ticket, null);

        Notification::assertSentTo($emailUser, TicketActivityNotification::class,
            fn ($n) => $n->event === 'ticket.department.created'
                && $n->headline === 'Novo ticket em Incidentes'
                && $n->via($emailUser) === ['mail']);
        Notification::assertSentTo($pushUser, TicketActivityNotification::class,
            fn ($n) => $n->event === 'ticket.department.created'
                && $n->via($pushUser) === ['database']);
        Notification::assertNotSentTo($waUser, TicketActivityNotification::class);
        Notification::assertNotSentTo($unrelated, TicketActivityNotification::class);
        Bus::assertDispatched(SendDepartmentWhatsApp::class, 1);
        Bus::assertDispatched(SendDepartmentWhatsApp::class,
            fn ($job) => $job->ticketId === $ticket->id
                && $job->departmentId === $department->id
                && $job->userId === $waUser->id
                && $job->event === 'created');
    }

    public function test_legacy_follow_keeps_email_and_sininho_and_send_only_cannot_receive(): void
    {
        Notification::fake();
        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $legacy = $this->user('Seguidor legado');
        $this->subscribe($legacy, $department);
        DB::table('department_user_access')->where('user_id', $legacy->id)->update([
            'follow_department' => true, 'notify_email' => false, 'notify_push' => false,
        ]);
        $ticket = $this->ticket($department);
        app(TicketNotifier::class)->opened($ticket, null);

        Notification::assertSentTo($legacy, TicketActivityNotification::class,
            fn ($n) => $n->event === 'ticket.department.created'
                && $n->via($legacy) === ['database', 'mail']);

        $this->actingAs($legacy)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 0, 'notify_whatsapp' => 0, 'notify_push' => 1,
        ])->assertRedirect();
        DB::table('department_user_access')->where('user_id', $legacy->id)
            ->update(['access_level' => 'send']);
        Notification::fake();
        app(TicketNotifier::class)->opened($this->ticket($department), null);
        Notification::assertNotSentTo($legacy, TicketActivityNotification::class);
    }

    public function test_whatsapp_goes_to_follower_and_is_not_resent_or_forwarded_to_customer(): void
    {
        $connection = app(WhatsAppConnection::class);
        $connection->save([
            'base_url' => 'https://evolution.example.test',
            'instance' => 'sutoorii-tickets',
            'api_key' => 'chave-ficticia',
        ]);
        Http::fake(['evolution.example.test/message/sendText/*' => Http::response(['ok' => true], 200)]);
        $department = Department::create(['name' => 'Desenvolvimento', 'active' => true]);
        $user = $this->user('Seguidor');
        $user->update(['whatsapp' => '5515999998888']);
        $user->refresh();
        $this->subscribe($user, $department);
        $this->actingAs($user)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 0, 'notify_whatsapp' => 1, 'notify_push' => 0,
        ])->assertRedirect();

        $ticket = $this->ticket($department);
        $ticket->update(['requester_whatsapp' => '5511988887777']);
        $job = new SendDepartmentWhatsApp($ticket->id, $department->id, $user->id, 'created', 'criado:'.$ticket->id);
        $job->handle($connection);
        $job->handle($connection);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['number'] === '5515999998888'
            && str_contains($request['text'], 'Desenvolvimento')
            && str_contains($request['text'], $ticket->number)
            && $request['number'] !== $ticket->requester_whatsapp);

        $this->actingAs($user)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 1, 'notify_whatsapp' => 0, 'notify_push' => 1,
        ])->assertRedirect();
        $ticket2 = $this->ticket($department);
        (new SendDepartmentWhatsApp($ticket2->id, $department->id, $user->id, 'created', 'criado:'.$ticket2->id))
            ->handle($connection);
        Http::assertSentCount(1);
    }
    public function test_browser_notification_poll_returns_only_users_own_department_alerts(): void
    {
        config(['mail.default' => 'array']);
        $department = Department::create(['name' => 'Atualizações', 'active' => true]);
        $follower = $this->user('Seguidor push');
        $stranger = $this->user('Não seguidor');
        $this->subscribe($follower, $department);
        $this->actingAs($follower)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 0, 'notify_whatsapp' => 0, 'notify_push' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $ticket = $this->ticket($department);
        app(TicketNotifier::class)->opened($ticket, null);

        $follower->refresh();
        $this->assertSame(1, $follower->unreadNotifications()->count());
        $response = $this->actingAs($follower)->getJson('/notificacoes/contador')
            ->assertOk()->assertJsonPath('unread', 1)
            ->assertJsonPath('department_alerts.0.title', 'Novo ticket em Atualizações')
            ->assertJsonPath('department_alerts.0.url', route('tickets.show', $ticket));
        $this->assertNotEmpty($response->json('department_alerts.0.id'));
        $this->actingAs($stranger)->getJson('/notificacoes/contador')
            ->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'department_alerts');
    }

    public function test_department_cancelled_event_remains_available_to_existing_followers(): void
    {
        Notification::fake();
        $department = Department::create(['name' => 'Financeiro', 'active' => true]);
        $follower = $this->user('Seguidor cancelamento');
        $this->subscribe($follower, $department);
        $this->actingAs($follower)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 1, 'notify_whatsapp' => 0, 'notify_push' => 1,
        ])->assertRedirect();

        $ticket = $this->ticket($department);
        app(TicketNotifier::class)->departmentEvent($ticket, 'cancelled');

        Notification::assertSentTo($follower, TicketActivityNotification::class,
            fn ($n) => $n->event === 'ticket.department.cancelled'
                && $n->headline === 'Ticket cancelado em Financeiro');
    }

}
