<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;

class TicketEventRecorder
{
    public function record(Ticket $ticket, ?User $actor, string $event, array $data = []): TicketEvent
    {
        return $ticket->events()->create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'data' => $data,
        ]);
    }
}
