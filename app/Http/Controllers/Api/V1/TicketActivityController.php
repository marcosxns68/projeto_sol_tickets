<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

class TicketActivityController extends BaseIntegrationController
{
    public function show(Request $request, string $number)
    {
        $ticket = $this->ticket($request, $number);

        $comments = $ticket->comments()
            ->where('visibility', 'public')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($comment) => [
                'type' => 'comment',
                'id' => $comment->id,
                'body' => $comment->body,
                'source' => $comment->source,
                'created_at' => $comment->created_at?->toIso8601String(),
            ]);

        $events = $ticket->events()
            ->whereIn('event', ['created.integration', 'status.changed', 'resolved', 'closed', 'reopened'])
            ->get()
            ->map(fn ($event) => [
                'type' => 'event',
                'event' => $event->event,
                'data' => collect($event->data ?? [])->only(['old_status', 'new_status'])->all(),
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'ticket' => $this->ticketPayload($ticket),
            'activity' => $comments->concat($events)->sortBy('created_at')->values(),
        ]);
    }
}
