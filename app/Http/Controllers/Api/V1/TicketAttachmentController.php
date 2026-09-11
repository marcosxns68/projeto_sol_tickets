<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attachment;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketAttachmentController extends BaseIntegrationController
{
    public function store(Request $request, string $number, TicketEventRecorder $events)
    {
        $ticket = $this->ticket($request, $number);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp,txt,csv,doc,docx,xls,xlsx,zip'],
        ]);

        $file = $data['file'];
        $path = $file->store('ticket-attachments/'.$ticket->id, 'local');

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'comment_id' => null,
            'uploaded_by' => null,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'expires_at' => now()->addDays(90),
        ]);

        $events->record($ticket, null, 'attachment.created.integration', [
            'attachment_id' => $attachment->id,
            'external_user_id' => $request->attributes->get('external_user_id'),
        ]);

        return response()->json([
            'attachment' => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'created_at' => $attachment->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
