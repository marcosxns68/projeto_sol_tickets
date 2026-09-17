<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DepartmentFollowNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, array $permissions = []): User
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

    private function access(User $user, Department $department, string $level, bool $follow = false): void
    {
        $user->departments()->syncWithoutDetaching([
            $department->id => [
                'access_level' => $level,
                'follow_department' => $follow,
            ],
        ]);
    }

    public function test_forwarding_notifies_followers_of_source_and_destination_departments(): void
    {
        Notification::fake();

        $source = Department::create(['name' => 'Suporte', 'active' => true]);
        $target = Department::create(['name' => 'Desenvolvimento', 'active' => true]);

        $actor = $this->user('Encaminhador', ['tickets.forward', 'tickets.view_department']);
        $sourceFollower = $this->user('Acompanha origem');
        $targetFollower = $this->user('Acompanha destino');

        $this->access($actor, $source, 'edit');
        $this->access($actor, $target, 'send');
        $this->access($sourceFollower, $source, 'view', true);
        $this->access($targetFollower, $target, 'view', true);

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket encaminhado',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'department_id' => $source->id,
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($actor)
            ->post('/tickets/'.$ticket->id.'/encaminhar', [
                'department_id' => $target->id,
                'reason' => 'Precisa do desenvolvimento',
            ])
            ->assertRedirect();

        Notification::assertSentTo($sourceFollower, TicketActivityNotification::class);
        Notification::assertSentTo($targetFollower, TicketActivityNotification::class);
        Notification::assertNotSentTo($actor, TicketActivityNotification::class);
    }
}
