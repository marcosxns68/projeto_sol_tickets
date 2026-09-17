<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PendingFinalizationTest extends TestCase
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

    private function ticket(User $assignee): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket finalização',
            'description' => 'Descrição do ticket',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'assignee_id' => $assignee->id,
            'due_at' => now()->addDays(2),
        ]);
    }

    public function test_ticket_notifications_for_internal_users_use_database_channel_too(): void
    {
        $user = $this->user('Usuário notificado');
        $ticket = $this->ticket($user);
        $notification = new TicketActivityNotification($ticket, 'Atualização', 'Mensagem');

        $this->assertContains('database', $notification->via($user));
    }

    public function test_attachment_can_be_uploaded_privately_by_authorized_user(): void
    {
        Storage::fake('local');
        $user = $this->user('Anexos', ['tickets.manage_attachments']);
        $ticket = $this->ticket($user);

        $response = $this->actingAs($user)->post('/tickets/'.$ticket->id.'/anexos', [
            'file' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('attachments', [
            'ticket_id' => $ticket->id,
            'original_name' => 'documento.pdf',
        ]);
    }

    public function test_recurrence_can_be_configured_for_ticket(): void
    {
        $user = $this->user('Recorrência', ['tickets.recurrence']);
        $ticket = $this->ticket($user);

        $response = $this->actingAs($user)->post('/tickets/'.$ticket->id.'/recorrencia', [
            'frequency' => 'daily',
            'interval' => 1,
            'next_run_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'active' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('recurrences', [
            'source_ticket_id' => $ticket->id,
            'frequency' => 'daily',
            'interval' => 1,
            'active' => 1,
        ]);
    }

    public function test_maintenance_and_recurrence_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('tickets:maintenance', $commands);
        $this->assertArrayHasKey('tickets:process-recurrences', $commands);
    }
}
