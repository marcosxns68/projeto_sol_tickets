<?php

namespace App\Http\Controllers;

use App\Models\Recurrence;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketRecurrenceController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketEventRecorder $events)
    {
        $actor = $request->user();
        $this->authorizeTicket($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.recurrence'), 403);

        $data = $request->validate([
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'interval' => ['required', 'integer', 'min:1', 'max:365'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'next_run_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:next_run_at'],
            'active' => ['nullable', 'boolean'],
        ]);

        if ($data['frequency'] === 'weekly' && empty($data['weekdays'])) {
            return back()->withErrors(['weekdays' => 'Escolha pelo menos um dia da semana.'])->withInput();
        }

        $recurrence = Recurrence::query()->firstOrNew(['source_ticket_id' => $ticket->id]);
        $recurrence->fill([
            'frequency' => $data['frequency'],
            'interval' => (int) $data['interval'],
            'weekdays' => $data['frequency'] === 'weekly' ? array_values(array_unique($data['weekdays'] ?? [])) : null,
            'next_run_at' => $data['next_run_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
        ]);
        $recurrence->save();

        $events->record($ticket, $actor, 'recurrence.configured', [
            'frequency' => $recurrence->frequency,
            'interval' => $recurrence->interval,
            'next_run_at' => $recurrence->next_run_at?->toIso8601String(),
            'ends_at' => $recurrence->ends_at?->toIso8601String(),
            'active' => $recurrence->active,
        ]);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Recorrência atualizada.');
    }

    public function destroy(Request $request, Ticket $ticket, TicketEventRecorder $events)
    {
        $actor = $request->user();
        $this->authorizeTicket($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.recurrence'), 403);

        $recurrence = $ticket->recurrence()->firstOrFail();
        $recurrence->delete();
        $events->record($ticket, $actor, 'recurrence.removed');

        return redirect()->route('tickets.show', $ticket)->with('success', 'Recorrência removida.');
    }

    private function authorizeTicket($actor, Ticket $ticket): void
    {
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);
    }
}
