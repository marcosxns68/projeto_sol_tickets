<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketChecklistController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketEventRecorder $events)
    {
        $actor = $request->user();
        $this->authorizeTicket($actor, $ticket);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'required' => ['nullable', 'boolean'],
        ]);

        $item = $ticket->checklist()->create([
            'text' => $data['text'],
            'required' => (bool) ($data['required'] ?? false),
            'position' => ((int) $ticket->checklist()->max('position')) + 1,
        ]);

        $events->record($ticket, $actor, 'checklist.added', ['item_id' => $item->id, 'text' => $item->text]);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Item adicionado ao checklist.');
    }

    public function toggle(Request $request, Ticket $ticket, ChecklistItem $item, TicketEventRecorder $events)
    {
        $actor = $request->user();
        $this->authorizeTicket($actor, $ticket);
        abort_unless($item->ticket_id === $ticket->id, 404);

        $completed = !$item->completed;
        $item->update([
            'completed' => $completed,
            'completed_by' => $completed ? $actor->id : null,
            'completed_at' => $completed ? now() : null,
        ]);

        $events->record($ticket, $actor, 'checklist.toggled', [
            'item_id' => $item->id,
            'text' => $item->text,
            'completed' => $completed,
        ]);

        return redirect()->route('tickets.show', $ticket);
    }

    public function destroy(Request $request, Ticket $ticket, ChecklistItem $item, TicketEventRecorder $events)
    {
        $actor = $request->user();
        $this->authorizeTicket($actor, $ticket);
        abort_unless($item->ticket_id === $ticket->id, 404);

        $data = ['item_id' => $item->id, 'text' => $item->text];
        $item->delete();
        $events->record($ticket, $actor, 'checklist.removed', $data);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Item removido do checklist.');
    }

    private function authorizeTicket($actor, Ticket $ticket): void
    {
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);
        abort_unless($actor->hasPermission('tickets.manage_checklist'), 403);
    }
}
