<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\IntegrationWebhookDispatcher;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use App\Services\RequesterReplyWorkflow;
use Illuminate\Http\Request;

class TicketCommentController extends Controller
{
    public function store(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        IntegrationWebhookDispatcher $webhooks,
        TicketNotifier $notifier,
        RequesterReplyWorkflow $replyWorkflow,
    ) {
        $actor = $request->user();
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);

        $data = $request->validate([
            'visibility' => ['required', 'in:public,internal'],
            'body' => ['required', 'string', 'max:10000'],
            'notify_requester' => ['nullable', 'boolean'],
            'notify_responsible' => ['nullable', 'boolean'],
            'notify_collaborators' => ['nullable', 'boolean'],
            'notify_followers' => ['nullable', 'boolean'],
        ]);

        $isRequester = (int) $ticket->requester_user_id === (int) $actor->id;
        $permission = $data['visibility'] === 'public' ? 'tickets.comment' : 'tickets.internal_note';
        abort_unless($actor->hasPermission($permission) || ($data['visibility'] === 'public' && $isRequester), 403);

        $comment = $ticket->comments()->create([
            'user_id' => $actor->id,
            'visibility' => $data['visibility'],
            'body' => $data['body'],
            'source' => 'web',
        ]);

        $events->record($ticket, $actor, $data['visibility'] === 'public' ? 'comment.public' : 'comment.internal', [
            'comment_id' => $comment->id,
        ]);

        if ($data['visibility'] === 'public' && $isRequester && $replyWorkflow->markRequesterReplied($ticket)) {
            $events->record($ticket, $actor, 'status.changed', [
                'source' => 'requester_reply',
                'automatic' => true,
                'status' => $ticket->status?->name,
            ]);
        }

        if ($data['visibility'] === 'public') {
            $webhooks->dispatch($ticket, 'ticket.comment.created', [
                'comment' => [
                    'body' => $comment->body,
                    'created_at' => $comment->created_at?->toIso8601String(),
                ],
            ]);

            $notifier->publicComment($ticket, $actor, [
                'requester' => $request->boolean('notify_requester'),
                'responsible' => $request->boolean('notify_responsible'),
                'collaborators' => $request->boolean('notify_collaborators'),
                'followers' => $request->boolean('notify_followers'),
            ]);
            // A escolha do operador controla ambos os canais para esta resposta.
            // A automação de comentários continua respeitando a configuração global.
            if (!$isRequester && $request->boolean('notify_requester')) {
                $notifier->publicCommentWhatsApp($ticket, $actor, $comment->id);
            }
        }

        return redirect()->route('tickets.show', $ticket)->with('success', $data['visibility'] === 'public' ? 'Comentário adicionado.' : 'Nota interna adicionada.');
    }
}
