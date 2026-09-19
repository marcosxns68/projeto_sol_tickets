<?php

namespace App\Services;

use App\Models\Status;
use App\Models\Ticket;

class RequesterReplyWorkflow
{
    /**
     * Identifica uma nova resposta do solicitante sem reabrir automaticamente
     * tickets concluídos, cancelados ou enviados para a lixeira.
     */
    public function markRequesterReplied(Ticket $ticket): bool
    {
        $ticket->loadMissing('status');

        if ($ticket->trashed_at || !$ticket->status ||
            in_array($ticket->status->category, ['completed', 'cancelled'], true)) {
            return false;
        }

        $replied = Status::system('requester_replied');
        if (!$replied || (int) $ticket->status_id === (int) $replied->id) {
            return false;
        }

        $ticket->update(['status_id' => $replied->id, 'completed_at' => null]);
        $ticket->setRelation('status', $replied);

        return true;
    }
}
