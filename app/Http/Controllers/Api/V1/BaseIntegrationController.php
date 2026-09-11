<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedSystem;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class BaseIntegrationController extends Controller
{
    protected function integration(Request $request): ConnectedSystem
    {
        return $request->attributes->get('integration');
    }

    protected function scopedTickets(Request $request): Builder
    {
        return Ticket::query()->forExternalActor(
            $this->integration($request),
            (string) $request->attributes->get('external_user_id'),
            (string) $request->attributes->get('external_user_role')
        );
    }

    protected function ticket(Request $request, string $number): Ticket
    {
        $ticket = $this->scopedTickets($request)
            ->with(['status', 'system'])
            ->where('number', $number)
            ->first();

        abort_unless($ticket, 404, 'Ticket não encontrado.');
        return $ticket;
    }

    protected function ticketPayload(Ticket $ticket): array
    {
        $ticket->loadMissing('status');

        return [
            'number' => $ticket->number,
            'external_reference' => $ticket->external_reference,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status' => $ticket->status?->name,
            'status_key' => $ticket->status?->system_key,
            'requester_name' => $ticket->requester_name,
            'requester_email' => $ticket->requester_email,
            'external_user_id' => $ticket->external_requester_id,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'completed_at' => $ticket->completed_at?->toIso8601String(),
        ];
    }
}
