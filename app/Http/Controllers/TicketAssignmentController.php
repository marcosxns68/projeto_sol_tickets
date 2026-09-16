<?php

namespace App\Http\Controllers;

use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DepartmentAccess;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketAssignmentController extends Controller
{
    public function assume(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
    ) {
        $user = $request->user();
        abort_unless($user->hasPermission('tickets.assume'), 403);
        abort_unless($ticket->department_id && $departmentAccess->canView($user, $ticket->department_id), 403);
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

        $notifier->reassigned($ticket, null, $user, $user);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket assumido por você.');
    }

    public function reassign(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
    ) {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.reassign'), 403);
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);
        if ($ticket->department_id && !$actor->hasPermission('tickets.view_all')) {
            abort_unless($departmentAccess->canEdit($actor, $ticket->department_id), 403);
        }

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

        $notifier->reassigned($ticket, $old, $target, $actor);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Responsável atualizado.');
    }
}
