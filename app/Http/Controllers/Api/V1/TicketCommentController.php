<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketCommentController extends BaseIntegrationController
{
    public function store(Request $request, string $number, TicketEventRecorder $events)
    {
        $ticket = $this->ticket($request, $number);
        $integration = $this->integration($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'external_message_id' => ['nullable', 'string', 'max:150'],
        ]);

        $messageId = !empty($data['external_message_id'])
            ? 'integration:'.$integration->id.':'.$data['external_message_id']
            : null;

        if ($messageId && ($existing = $ticket->comments()->where('message_id', $messageId)->first())) {
            return response()->json([
                'comment' => [
                    'id' => $existing->id,
                    'body' => $existing->body,
                    'created_at' => $existing->created_at?->toIso8601String(),
                ],
            ]);
        }

        $comment = $ticket->comments()->create([
            'user_id' => null,
            'visibility' => 'public',
            'body' => trim($data['body']),
            'source' => 'integration',
            'message_id' => $messageId,
        ]);

        $events->record($ticket, null, 'comment.public.integration', [
            'comment_id' => $comment->id,
            'external_user_id' => $request->attributes->get('external_user_id'),
        ]);

        return response()->json([
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
