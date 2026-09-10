<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketParticipantController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketEventRecorder $events)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.manage_participants'), 403);
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:collaborator,follower'],
            'notify_status' => ['nullable', 'boolean'],
            'notify_comments' => ['nullable', 'boolean'],
            'notify_attachments' => ['nullable', 'boolean'],
        ]);

        $participant = User::whereKey($data['user_id'])->where('active', true)->firstOrFail();
        $existing = $ticket->participants()->where('users.id', $participant->id)->first();
        $oldType = $existing?->pivot?->type;

        $ticket->participants()->syncWithoutDetaching([
            $participant->id => [
                'type' => $data['type'],
                'notify_status' => (bool) ($data['notify_status'] ?? true),
                'notify_comments' => (bool) ($data['notify_comments'] ?? true),
                'notify_attachments' => (bool) ($data['notify_attachments'] ?? true),
            ],
        ]);

        $events->record($ticket, $actor, $existing ? 'participant_changed' : 'participant_added', [
            'user_id' => $participant->id,
            'user_name' => $participant->name,
            'old_type' => $oldType,
            'type' => $data['type'],
        ]);

        return redirect()->route('tickets.show', $ticket)->with('success', $data['type'] === 'collaborator' ? 'Colaborador atualizado.' : 'Seguidor atualizado.');
    }

    public function destroy(Request $request, Ticket $ticket, User $user, TicketEventRecorder $events)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.manage_participants'), 403);
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);

        $participant = $ticket->participants()->where('users.id', $user->id)->firstOrFail();
        $type = $participant->pivot->type;
        $ticket->participants()->detach($user->id);

        $events->record($ticket, $actor, 'participant_removed', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'type' => $type,
        ]);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Participante removido.');
    }
}
