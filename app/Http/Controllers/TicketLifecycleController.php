<?php

namespace App\Http\Controllers;

use App\Models\Status;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use App\Services\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketLifecycleController extends Controller
{
    public function requestCompletion(Request $request, Ticket $ticket, TicketEventRecorder $events, WebhookDispatcher $webhooks)
    {
        $actor = $request->user();
        $this->ensureVisible($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.request_completion'), 403);

        $isCollaborator = $ticket->participants()->where('users.id', $actor->id)->wherePivot('type', 'collaborator')->exists();
        abort_unless($ticket->assignee_id === $actor->id || $isCollaborator, 403);

        return $this->transition($ticket, $actor, 'completion_requested', 'completion.requested', $events, $webhooks, 'Conclusão solicitada.', false, 'ticket.status.changed');
    }

    public function resolve(Request $request, Ticket $ticket, TicketEventRecorder $events, WebhookDispatcher $webhooks)
    {
        $actor = $request->user();
        $this->ensureVisible($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.resolve'), 403);
        abort_unless($ticket->assignee_id === $actor->id, 403);

        if ($ticket->checklist()->where('required', true)->where('completed', false)->exists()) {
            return back()->withErrors(['ticket' => 'Conclua todos os itens obrigatórios do checklist antes de resolver o ticket.']);
        }

        return $this->transition($ticket, $actor, 'resolved', 'resolved', $events, $webhooks, 'Ticket resolvido.', true, 'ticket.resolved');
    }

    public function close(Request $request, Ticket $ticket, TicketEventRecorder $events, WebhookDispatcher $webhooks)
    {
        $actor = $request->user();
        $this->ensureVisible($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.close'), 403);

        return $this->transition($ticket, $actor, 'closed', 'closed', $events, $webhooks, 'Ticket fechado.', true, 'ticket.closed');
    }

    public function cancel(Request $request, Ticket $ticket, TicketEventRecorder $events, WebhookDispatcher $webhooks)
    {
        $actor = $request->user();
        $this->ensureVisible($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.cancel'), 403);

        return $this->transition($ticket, $actor, 'cancelled', 'cancelled', $events, $webhooks, 'Ticket cancelado.', true, 'ticket.status.changed');
    }

    public function reopen(Request $request, Ticket $ticket, TicketEventRecorder $events, WebhookDispatcher $webhooks)
    {
        $actor = $request->user();
        $this->ensureVisible($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.reopen'), 403);

        $status = Status::system('in_progress');
        abort_unless($status, 500, 'Status Em andamento não configurado.');
        $oldStatus = $ticket->status?->name;

        DB::transaction(function () use ($ticket, $actor, $status, $oldStatus, $events) {
            $ticket->update(['status_id' => $status->id, 'completed_at' => null]);
            $events->record($ticket, $actor, 'reopened', ['old_status' => $oldStatus, 'new_status' => $status->name]);
        });

        $webhooks->queue($ticket->fresh(['status', 'system']), 'ticket.reopened', [
            'old_status' => $oldStatus,
            'new_status' => $status->name,
        ]);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket reaberto.');
    }

    private function transition(Ticket $ticket, $actor, string $systemKey, string $event, TicketEventRecorder $events, WebhookDispatcher $webhooks, string $message, bool $complete = false, string $webhookEvent = 'ticket.status.changed')
    {
        $status = Status::system($systemKey);
        abort_unless($status, 500, 'Status necessário não configurado.');
        $oldStatus = $ticket->status?->name;

        DB::transaction(function () use ($ticket, $actor, $status, $oldStatus, $events, $event, $complete) {
            $ticket->update([
                'status_id' => $status->id,
                'completed_at' => $complete ? now() : $ticket->completed_at,
            ]);
            $events->record($ticket, $actor, $event, ['old_status' => $oldStatus, 'new_status' => $status->name]);
        });

        $webhooks->queue($ticket->fresh(['status', 'system']), $webhookEvent, [
            'old_status' => $oldStatus,
            'new_status' => $status->name,
        ]);

        return redirect()->route('tickets.show', $ticket)->with('success', $message);
    }

    private function ensureVisible($actor, Ticket $ticket): void
    {
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);
    }
}
