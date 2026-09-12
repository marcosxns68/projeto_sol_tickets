<?php

namespace App\Http\Controllers;

use App\Models\Label;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketLabelController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketEventRecorder $events)
    {
        $ticket = $this->authorizedTicket($request, $ticket);
        $data = $request->validate([
            'label_id' => ['required', 'integer', 'exists:labels,id'],
        ]);

        $label = Label::findOrFail((int) $data['label_id']);
        $alreadyAttached = $ticket->labels()->whereKey($label->id)->exists();
        $ticket->labels()->syncWithoutDetaching([$label->id]);

        if (!$alreadyAttached) {
            $events->record($ticket, $request->user(), 'label.added', [
                'label_id' => $label->id,
                'label_name' => $label->name,
                'label_color' => $label->color,
            ]);
        }

        return redirect()->route('tickets.show', $ticket)->with('success', 'Etiqueta adicionada.');
    }

    public function destroy(Request $request, Ticket $ticket, Label $label, TicketEventRecorder $events)
    {
        $ticket = $this->authorizedTicket($request, $ticket);
        $attached = $ticket->labels()->whereKey($label->id)->exists();
        $ticket->labels()->detach($label->id);

        if ($attached) {
            $events->record($ticket, $request->user(), 'label.removed', [
                'label_id' => $label->id,
                'label_name' => $label->name,
                'label_color' => $label->color,
            ]);
        }

        return redirect()->route('tickets.show', $ticket)->with('success', 'Etiqueta removida.');
    }

    private function authorizedTicket(Request $request, Ticket $ticket): Ticket
    {
        abort_unless($request->user()->hasPermission('tickets.manage_labels'), 403);

        return Ticket::query()->visibleTo($request->user())->whereKey($ticket->id)->firstOrFail();
    }
}
