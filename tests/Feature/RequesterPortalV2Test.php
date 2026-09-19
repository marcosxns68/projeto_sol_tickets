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
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RequesterPortalV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function ticket(string $email, string $title, string $statusKey = 'new'): Ticket
    {
        $department = Department::firstOrCreate(['name' => 'Suporte'], ['active' => true]);
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => $title,
            'description' => 'Descrição pública do chamado',
            'priority' => 'normal',
            'status_id' => Status::system($statusKey)->id,
            'department_id' => $department->id,
            'requester_name' => 'Cliente Manual',
            'requester_email' => strtolower($email),
            'due_at' => now()->addDay(),
        ]);
    }

    public function test_signed_requester_index_lists_open_and_closed_tickets_only_for_same_email(): void
    {
        $open = $this->ticket('cliente@example.com', 'Chamado aberto');
        $closed = $this->ticket('cliente@example.com', 'Chamado encerrado', 'closed');
        $other = $this->ticket('outro@example.com', 'Chamado de outra pessoa');

        $url = URL::signedRoute('requester.index', ['email' => 'cliente@example.com']);
        $this->get($url)
            ->assertOk()
            ->assertSee($open->number)
            ->assertSee($closed->number)
            ->assertSee('Chamado aberto')
            ->assertSee('Chamado encerrado')
            ->assertDontSee($other->number)
            ->assertDontSee('Chamado de outra pessoa');
    }

    public function test_requester_detail_never_exposes_internal_notes(): void
    {
        $ticket = $this->ticket('cliente@example.com', 'Ticket com comentários');
        $ticket->comments()->create([
            'user_id' => null,
            'visibility' => 'public',
            'body' => 'Resposta pública da equipe',
            'source' => 'web',
        ]);
        $ticket->comments()->create([
            'user_id' => null,
            'visibility' => 'internal',
            'body' => 'SEGREDO INTERNO NÃO PODE APARECER',
            'source' => 'web',
        ]);

        $url = URL::signedRoute('requester.show', [
            'ticket' => $ticket->id,
            'email' => 'cliente@example.com',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Resposta pública da equipe')
            ->assertDontSee('SEGREDO INTERNO NÃO PODE APARECER');
    }

    public function test_unsigned_or_wrong_email_requester_link_is_rejected(): void
    {
        $ticket = $this->ticket('cliente@example.com', 'Protegido');

        $this->get('/minhas-solicitacoes?email=cliente@example.com')->assertForbidden();

        $wrong = URL::signedRoute('requester.show', [
            'ticket' => $ticket->id,
            'email' => 'outro@example.com',
        ]);
        $this->get($wrong)->assertForbidden();
    }

    public function test_requester_can_add_only_public_comment_through_signed_portal(): void
    {
        $ticket = $this->ticket('cliente@example.com', 'Responder pelo portal');
        $url = URL::signedRoute('requester.comments.store', [
            'ticket' => $ticket->id,
            'email' => 'cliente@example.com',
        ]);

        $this->post($url, ['body' => 'Retorno do solicitante'])->assertRedirect();

        $this->assertDatabaseHas('comments', [
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'visibility' => 'public',
            'body' => 'Retorno do solicitante',
            'source' => 'requester',
        ]);
    }

    public function test_internal_requester_can_comment_publicly_without_general_comment_permission(): void
    {
        $department = Department::create(['name' => 'Interno', 'active' => true]);
        $role = Role::create(['name' => 'Solicitante', 'active' => true]);
        $requester = User::create([
            'name' => 'Solicitante Interno',
            'email' => 'solicitante@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Meu chamado',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'department_id' => $department->id,
            'requester_user_id' => $requester->id,
            'requester_name' => $requester->name,
            'requester_email' => $requester->email,
        ]);

        $this->actingAs($requester)->post('/tickets/'.$ticket->id.'/comentarios', [
            'visibility' => 'public',
            'body' => 'Complemento do solicitante',
        ])->assertRedirect();

        $this->assertDatabaseHas('comments', [
            'ticket_id' => $ticket->id,
            'user_id' => $requester->id,
            'visibility' => 'public',
            'body' => 'Complemento do solicitante',
        ]);
    }

    // Regressão: uma resposta do cliente precisa devolver o ticket ao atendimento ativo.
    public function test_requester_reply_sets_requester_replied_status_and_notifies_assignee(): void
    {
        Notification::fake();

        $waiting = Status::system('waiting_customer');
        $replied = Status::system('requester_replied');

        $assignee = User::create([
            'name' => 'Responsável Cliente',
            'email' => 'responsavel-cliente@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'active' => true,
        ]);

        $ticket = $this->ticket('cliente@example.com', 'Aguardando retorno');
        $ticket->update([
            'status_id' => $waiting->id,
            'assignee_id' => $assignee->id,
        ]);

        $url = URL::signedRoute('requester.comments.store', [
            'ticket' => $ticket->id,
            'email' => 'cliente@example.com',
        ]);

        $this->post($url, ['body' => 'Já respondi o que faltava.'])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($replied->id, $ticket->status_id);
        Notification::assertSentTo($assignee, TicketActivityNotification::class);
    }


    public function test_requester_reply_on_closed_ticket_does_not_reopen_it_automatically(): void
    {
        $ticket = $this->ticket('cliente@example.com', 'Ticket encerrado', 'closed');
        $url = URL::signedRoute('requester.comments.store', [
            'ticket' => $ticket->id,
            'email' => 'cliente@example.com',
        ]);

        $this->post($url, ['body' => 'Ainda tenho outra dúvida.'])->assertRedirect();
        $this->assertSame(Status::system('closed')->id, $ticket->fresh()->status_id);
    }

}
