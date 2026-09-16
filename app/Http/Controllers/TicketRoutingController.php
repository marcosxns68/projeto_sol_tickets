<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use App\Services\DepartmentAccess;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketRoutingController extends Controller
{
    public function forward(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
    ) {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.forward'), 403);
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);
        if ($ticket->department_id && !$actor->hasPermission('tickets.view_all')) {
            abort_unless($departmentAccess->canEdit($actor, $ticket->department_id), 403);
        }

        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notify_requester' => ['nullable', 'boolean'],
        ]);

        $target = Department::whereKey($data['department_id'])->where('active', true)->firstOrFail();
        abort_unless($departmentAccess->canSend($actor, $target), 403);

        $source = $ticket->department;
        $oldAssignee = $ticket->assignee;
        $forwarded = Status::system('forwarded');
        abort_unless($forwarded, 500, 'Status Encaminhado não configurado.');

        DB::transaction(function () use ($ticket, $actor, $target, $source, $oldAssignee, $forwarded, $data, $events) {
            $ticket->update([
                'department_id' => $target->id,
                'assignee_id' => null,
                'status_id' => $forwarded->id,
            ]);

            $events->record($ticket, $actor, 'forwarded', [
                'from_department_id' => $source?->id,
                'from_department_name' => $source?->name,
                'to_department_id' => $target->id,
                'to_department_name' => $target->name,
                'removed_assignee_id' => $oldAssignee?->id,
                'removed_assignee_name' => $oldAssignee?->name,
                'reason' => $data['reason'] ?? null,
            ]);
        });

        $ticket->refresh()->loadMissing(['department', 'requesterUser']);
        if ($oldAssignee) {
            $notifier->reassigned($ticket, $oldAssignee, null, $actor);
        }
        $notifier->departmentEvent($ticket, 'entered', $actor);
        if (!array_key_exists('notify_requester', $data) || (bool) $data['notify_requester']) {
            $notifier->requesterChanged($ticket, $actor, 'Ticket encaminhado');
        }

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket encaminhado para '.$target->name.'.');
    }
}
