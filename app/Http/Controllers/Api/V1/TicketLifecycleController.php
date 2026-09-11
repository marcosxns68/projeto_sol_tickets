<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Status;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketLifecycleController extends BaseIntegrationController
{
    public function close(Request $request, string $number, TicketEventRecorder $events)
    {
        $ticket = $this->ticket($request, $number);
        abort_if($ticket->status?->category === 'cancelled', 409, 'Ticket cancelado não pode ser fechado.');

        $closed = Status::system('closed');
        abort_unless($closed, 500, 'Status Fechado não configurado.');

        $old = $ticket->status?->name;
        $ticket->update(['status_id' => $closed->id, 'completed_at' => now()]);
        $events->record($ticket, null, 'closed', ['old_status' => $old, 'new_status' => $closed->name]);

        return response()->json(['ticket' => $this->ticketPayload($ticket->fresh('status'))]);
    }

    public function reopen(Request $request, string $number, TicketEventRecorder $events)
    {
        $ticket = $this->ticket($request, $number);
        abort_unless($ticket->status?->category === 'completed', 409, 'Somente tickets concluídos podem ser reabertos.');

        $progress = Status::system('in_progress');
        abort_unless($progress, 500, 'Status Em andamento não configurado.');

        $old = $ticket->status?->name;
        $ticket->update(['status_id' => $progress->id, 'completed_at' => null]);
        $events->record($ticket, null, 'reopened', ['old_status' => $old, 'new_status' => $progress->name]);

        return response()->json(['ticket' => $this->ticketPayload($ticket->fresh('status'))]);
    }
}
