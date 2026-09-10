<?php

namespace App\Http\Controllers;

use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketAssignmentController extends Controller
{
    public function assume(Request $request, Ticket $ticket, TicketEventRecorder $events)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('tickets.assume'), 403);
        abort_unless($ticket->department_id && $user->department_id === $ticket->department_id, 403);
        abort_if($ticket->assignee_id !== null, 409, 'Este ticket já possui responsável.');

        DB::transaction(function () use ($ticket, $user, $events) {
            $oldStatus = $ticket->status?->name;
            $inProgress = Status::system('in_progress');
            abort_unless($inProgress, 500, 'Status Em andamento não configurado.');

            $ticket->update([
                'assignee_id' => $user->id,
                'status_id' => $inProgress->id,
            ]);

            $events->record($ticket, $user, 'assumed', [
                'assignee_id' => $user->id,
                'assignee_name' => $user->name,
                'old_status' => $oldStatus,
                'new_status' => $inProgress->name,
            ]);
        });

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket assumido por você.');
    }

    public function reassign(Request $request, Ticket $ticket, TicketEventRecorder $events)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.reassign'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $target = User::whereKey($data['user_id'])->where('active', true)->firstOrFail();
        $old = $ticket->assignee;

        DB::transaction(function () use ($ticket, $actor, $target, $old, $events) {
            $ticket->update(['assignee_id' => $target->id]);

            $events->record($ticket, $actor, 'reassigned', [
                'old_assignee_id' => $old?->id,
                'old_assignee_name' => $old?->name,
                'new_assignee_id' => $target->id,
                'new_assignee_name' => $target->name,
            ]);
        });

        return redirect()->route('tickets.show', $ticket)->with('success', 'Responsável atualizado.');
    }
}
