<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\IntegrationWebhookDispatcher;
use App\Services\RequesterReplyWorkflow;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class RequesterPortalController extends Controller
{
    public function index(Request $request)
    {
        $email = $this->requesterEmail($request);

        $tickets = Ticket::query()
            ->whereRaw('LOWER(requester_email) = ?', [$email])
            ->with(['status', 'department'])
            ->latest('id')
            ->get();

        return view('requester.index', compact('tickets', 'email'));
    }

    public function show(Request $request, Ticket $ticket)
    {
        $email = $this->requesterEmail($request);
        $this->ensureRequesterOwnsTicket($ticket, $email);

        $ticket->load([
            'status',
            'department',
            'comments' => fn ($query) => $query
                ->where('visibility', 'public')
                ->with('user')
                ->orderBy('created_at'),
        ]);

        return view('requester.show', compact('ticket', 'email'));
    }

    public function comment(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        IntegrationWebhookDispatcher $webhooks,
        TicketNotifier $notifier,
        RequesterReplyWorkflow $replyWorkflow,
    ) {
        $email = $this->requesterEmail($request);
        $this->ensureRequesterOwnsTicket($ticket, $email);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $comment = $ticket->comments()->create([
            'user_id' => null,
            'visibility' => 'public',
            'body' => $data['body'],
            'source' => 'requester',
        ]);

        $events->record($ticket, null, 'comment.public', [
            'comment_id' => $comment->id,
            'source' => 'requester',
        ]);

        if ($replyWorkflow->markRequesterReplied($ticket)) {
            $events->record($ticket, null, 'status.changed', [
                'source' => 'requester_reply',
                'automatic' => true,
                'status' => $ticket->status?->name,
            ]);
        }

        $webhooks->dispatch($ticket, 'ticket.comment.created', [
            'comment' => [
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ],
        ]);

        $notifier->publicComment($ticket, null, [
            'requester' => false,
            'responsible' => true,
            'collaborators' => true,
            'followers' => true,
        ], $email);

        return redirect(URL::signedRoute('requester.show', [
            'ticket' => $ticket->id,
            'email' => $email,
        ]))->with('success', 'Resposta adicionada.');
    }

    private function requesterEmail(Request $request): string
    {
        $email = strtolower(trim((string) $request->query('email')));
        abort_unless($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL), 403);

        return $email;
    }

    private function ensureRequesterOwnsTicket(Ticket $ticket, string $email): void
    {
        abort_unless(
            strtolower(trim((string) $ticket->requester_email)) === $email,
            403
        );
    }
}
