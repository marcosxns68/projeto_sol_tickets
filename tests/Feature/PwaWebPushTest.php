<?php

namespace Tests\Feature;

use App\Jobs\SendDepartmentWebPush;
use App\Jobs\SendDepartmentWhatsApp;
use App\Models\Department;
use App\Models\PwaPushSubscription;
use App\Models\Setting;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PwaWebPush;
use App\Services\TicketNotifier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class PwaWebPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Pessoa PWA', 'email' => uniqid('pwa-').'@sutoorii.test',
            'password' => 'SenhaTeste123!', 'email_verified_at' => now(), 'active' => true,
        ]);
    }

    private function subscription(string $suffix = 'endpoint'): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ];
    }

    private function follow(User $user, Department $department, bool $push = true): void
    {
        DB::table('department_user_access')->insert([
            'user_id' => $user->id, 'department_id' => $department->id,
            'access_level' => 'view', 'follow_department' => true,
            'notify_email' => false, 'notify_whatsapp' => false, 'notify_push' => $push,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ticket(Department $department): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(), 'origin' => 'internal',
            'title' => 'Pedido de suporte', 'description' => 'Descrição',
            'priority' => 'normal', 'status_id' => Status::system('new')->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_vapid_keys_are_stable_public_key_only_is_exposed_and_private_key_encrypted(): void
    {
        $user = $this->user();
        $first = $this->actingAs($user)->getJson('/pwa-push/configuracao')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('publicKey');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{80,120}$/D', $first);
        $second = $this->actingAs($user)->getJson('/pwa-push/configuracao')->assertOk()
            ->json('publicKey');
        $this->assertSame($first, $second);

        $raw = Setting::where('key', 'pwa_push.vapid')->value('value');
        $data = json_decode($raw, true);
        $this->assertSame($first, $data['publicKey']);
        $this->assertArrayNotHasKey('privateKey', $data);
        $this->assertNotEmpty(Crypt::decryptString($data['encryptedPrivateKey']));
        $this->actingAs($user)->getJson('/pwa-push/estado')->assertJsonPath('devices', 0);
    }

    public function test_one_device_belongs_to_one_user_and_revocation_cannot_delete_another_users_device(): void
    {
        $first = $this->user();
        $second = $this->user();
        $subscription = $this->subscription('device-one');

        $this->actingAs($first)->postJson('/pwa-push/dispositivos', [
            'subscription' => $subscription, 'device_label' => 'Android PWA',
        ])->assertOk()->assertJsonPath('message', 'Notificações push ativadas neste dispositivo.');
        $stored = PwaPushSubscription::firstOrFail();
        $this->assertSame($first->id, $stored->user_id);
        $this->assertNotSame($subscription['endpoint'], $stored->getRawOriginal('subscription'));
        $this->assertSame($subscription['endpoint'], $stored->subscription['endpoint']);

        $this->actingAs($second)->deleteJson('/pwa-push/dispositivos', [
            'endpoint' => $subscription['endpoint'],
        ])->assertOk();
        $this->assertSame(1, PwaPushSubscription::count());

        // When the same PWA signs in as another account it must not keep
        // receiving the previous account's private department alerts.
        $this->actingAs($second)->postJson('/pwa-push/dispositivos', [
            'subscription' => $subscription,
        ])->assertOk();
        $this->assertSame(1, PwaPushSubscription::count());
        $this->assertSame($second->id, PwaPushSubscription::first()->user_id);
        $this->actingAs($second)->deleteJson('/pwa-push/dispositivos', [
            'endpoint' => $subscription['endpoint'],
        ])->assertOk();
        $this->assertSame(0, PwaPushSubscription::count());
    }

    public function test_private_or_unrecognized_endpoints_cannot_be_registered_for_server_requests(): void
    {
        $user = $this->user();
        foreach ([
            'http://localhost/push',
            'https://127.0.0.1/push',
            'https://internal.example.com/push',
            'https://fcm.googleapis.com.evil.test/fcm/send/x',
            'https://fcm.googleapis.com:443/fcm/send/x',
        ] as $url) {
            $subscription = $this->subscription();
            $subscription['endpoint'] = $url;
            $this->actingAs($user)->postJson('/pwa-push/dispositivos', [
                'subscription' => $subscription,
            ])->assertStatus(422);
        }
        $this->assertSame(0, PwaPushSubscription::count());
    }

    public function test_followed_department_queues_real_web_push_regardless_of_browser_polling_and_preserves_other_channels(): void
    {
        Bus::fake();
        Notification::fake();
        $user = $this->user();
        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $this->follow($user, $department);
        $ticket = $this->ticket($department);

        app(TicketNotifier::class)->opened($ticket, null);

        Bus::assertDispatched(SendDepartmentWebPush::class, fn ($job) =>
            $job->ticketId === $ticket->id && $job->userId === $user->id
            && $job->departmentId === $department->id && $job->event === 'created'
        );
        Bus::assertNotDispatched(SendDepartmentWhatsApp::class);
    }

    public function test_background_job_rechecks_department_access_and_push_opt_in_before_sending(): void
    {
        $user = $this->user();
        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $this->follow($user, $department);
        $ticket = $this->ticket($department);
        $subscription = $this->subscription('test-queue');
        $device = PwaPushSubscription::create([
            'user_id' => $user->id,
            'endpoint_hash' => hash('sha256', $subscription['endpoint']),
            'subscription' => $subscription,
        ]);

        $report = Mockery::mock();
        $report->shouldReceive('isSuccess')->once()->andReturn(true);
        $push = Mockery::mock(PwaWebPush::class);
        $push->shouldReceive('send')->once()
            ->with(Mockery::on(fn ($value) => $value['endpoint'] === $subscription['endpoint']),
                Mockery::on(fn ($payload) => $payload['url'] === route('tickets.show', $ticket, false)
                    && $payload['title'] === 'Novo ticket em Suporte'
                    && !str_contains($payload['body'], $ticket->description)))
            ->andReturn($report);

        $job = new SendDepartmentWebPush($ticket->id, $department->id, $user->id, 'created');
        $job->handle($push);
        $this->assertNotNull($device->fresh()->last_success_at);

        $push->shouldNotReceive('send');
        DB::table('department_user_access')->where('user_id', $user->id)
            ->where('department_id', $department->id)->update(['notify_push' => false]);
        $job->handle($push);
        DB::table('department_user_access')->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->update(['notify_push' => true, 'access_level' => 'send']);
        $job->handle($push);
    }
}
