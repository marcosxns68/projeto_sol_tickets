<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\IntegrationWebhookDispatcher;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketCommentController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketEventRecorder $events, IntegrationWebhookDispatcher $webhooks)
    {
        $actor = $request->user();
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);

        $data = $request->validate([
            'visibility' => ['required', 'in:public,internal'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $permission = $data['visibility'] === 'public' ? 'tickets.comment' : 'tickets.internal_note';
        abort_unless($actor->hasPermission($permission), 403);

        $comment = $ticket->comments()->create([
            'user_id' => $actor->id,
            'visibility' => $data['visibility'],
            'body' => $data['body'],
            'source' => 'web',
        ]);

        $events->record($ticket, $actor, $data['visibility'] === 'public' ? 'comment.public' : 'comment.internal', [
            'comment_id' => $comment->id,
        ]);

        if ($data['visibility'] === 'public') {
            $webhooks->dispatch($ticket, 'ticket.comment.created', [
                'comment' => [
                    'body' => $comment->body,
                    'created_at' => $comment->created_at?->toIso8601String(),
                ],
            ]);
        }

        return redirect()->route('tickets.show', $ticket)->with('success', $data['visibility'] === 'public' ? 'Comentário adicionado.' : 'Nota interna adicionada.');
    }
}
