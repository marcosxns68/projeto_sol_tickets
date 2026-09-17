<?php

namespace App\Services;

use App\Models\Status;
use App\Models\Ticket;

class RequesterReplyWorkflow
{
    public function resumeIfWaitingForCustomer(Ticket $ticket): bool
    {
        $ticket->loadMissing('status');

        $current = $ticket->status;
        if (!$current) {
            return false;
        }

        $isWaitingForCustomer = $current->system_key === 'waiting_customer'
            || mb_strtolower(trim((string) $current->name)) === 'aguardando cliente';

        if (!$isWaitingForCustomer) {
            return false;
        }

        $inProgress = Status::system('in_progress')
            ?? Status::query()->where('name', 'Em andamento')->first();

        if (!$inProgress || (int) $ticket->status_id === (int) $inProgress->id) {
            return false;
        }

        $ticket->update([
            'status_id' => $inProgress->id,
            'completed_at' => null,
        ]);

        $ticket->setRelation('status', $inProgress);

        return true;
    }
}
