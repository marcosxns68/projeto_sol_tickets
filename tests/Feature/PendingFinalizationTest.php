<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Label;
use App\Models\Permission;
use App\Models\Recurrence;
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

    private function ticket(User $assignee, $dueAt = null): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket finalização',
            'description' => 'Descrição do ticket',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'assignee_id' => $assignee->id,
            'due_at' => $dueAt ?? now()->addDays(2),
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

    public function test_video_attachment_is_rejected(): void
    {
        Storage::fake('local');
        $user = $this->user('Anexos vídeo', ['tickets.manage_attachments']);
        $ticket = $this->ticket($user);

        $response = $this->actingAs($user)->from('/tickets/'.$ticket->id)->post('/tickets/'.$ticket->id.'/anexos', [
            'file' => UploadedFile::fake()->create('video.mp4', 100, 'video/mp4'),
        ]);

        $response->assertRedirect('/tickets/'.$ticket->id);
        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('attachments', 0);
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

    public function test_due_recurrence_creates_a_new_unassigned_ticket_and_copies_checklist(): void
    {
        $user = $this->user('Origem recorrente');
        $ticket = $this->ticket($user);
        $ticket->checklist()->create([
            'text' => 'Item obrigatório',
            'required' => true,
            'completed' => true,
            'position' => 1,
        ]);

        Recurrence::create([
            'source_ticket_id' => $ticket->id,
            'frequency' => 'daily',
            'interval' => 1,
            'next_run_at' => now()->subMinute(),
            'active' => true,
        ]);

        $this->artisan('tickets:process-recurrences')->assertSuccessful();

        $this->assertDatabaseCount('tickets', 2);
        $generated = Ticket::query()->whereKeyNot($ticket->id)->firstOrFail();
        $this->assertNull($generated->assignee_id);
        $this->assertSame($ticket->title, $generated->title);
        $this->assertDatabaseHas('checklist_items', [
            'ticket_id' => $generated->id,
            'text' => 'Item obrigatório',
            'completed' => 0,
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $generated->id,
            'event' => 'recurrence_created',
        ]);
    }

    public function test_maintenance_marks_overdue_ticket_and_expires_attachment(): void
    {
        Storage::fake('local');
        $user = $this->user('Manutenção');
        $ticket = $this->ticket($user, now()->subHour());
        Storage::disk('local')->put('tickets/'.$ticket->id.'/expirado.pdf', 'conteudo');
        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by' => $user->id,
            'disk' => 'local',
            'path' => 'tickets/'.$ticket->id.'/expirado.pdf',
            'original_name' => 'expirado.pdf',
            'mime_type' => 'application/pdf',
            'size' => 8,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('tickets:maintenance')->assertSuccessful();

        $label = Label::query()->where('name', 'Atrasada')->firstOrFail();
        $this->assertTrue($ticket->labels()->whereKey($label->id)->exists());
        $this->assertNotNull($attachment->fresh()->deleted_at);
        Storage::disk('local')->assertMissing('tickets/'.$ticket->id.'/expirado.pdf');
    }

    public function test_maintenance_and_recurrence_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('tickets:maintenance', $commands);
        $this->assertArrayHasKey('tickets:process-recurrences', $commands);
    }
}
