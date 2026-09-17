<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TicketAttachmentController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketEventRecorder $events, TicketNotifier $notifier)
    {
        $actor = $request->user();
        $this->authorizeTicket($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.manage_attachments'), 403);

        $maxMb = max(1, (int) Setting::getValue('attachments.max_mb', 20));
        $request->validate([
            'file' => ['required', 'file', 'max:'.($maxMb * 1024)],
        ]);

        $file = $request->file('file');
        $mime = strtolower((string) $file->getMimeType());
        if (str_starts_with($mime, 'video/')) {
            throw ValidationException::withMessages([
                'file' => 'Arquivos de vídeo não são permitidos nos tickets.',
            ]);
        }

        $retentionDays = max(1, (int) Setting::getValue('attachments.retention_days', 365));
        $path = $file->store('tickets/'.$ticket->id, 'local');

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by' => $actor->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'expires_at' => now()->addDays($retentionDays),
        ]);

        $events->record($ticket, $actor, 'attachment.added', [
            'attachment_id' => $attachment->id,
            'name' => $attachment->original_name,
            'size' => $attachment->size,
            'expires_at' => $attachment->expires_at?->toIso8601String(),
        ]);

        $notifier->attachmentAdded($ticket, $actor, $attachment->original_name);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Anexo enviado.');
    }

    public function download(Request $request, Ticket $ticket, Attachment $attachment)
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_unless((int) $attachment->ticket_id === (int) $ticket->id, 404);
        abort_if($attachment->deleted_at !== null, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, Ticket $ticket, Attachment $attachment, TicketEventRecorder $events)
    {
        $actor = $request->user();
        $this->authorizeTicket($actor, $ticket);
        abort_unless($actor->hasPermission('tickets.manage_attachments'), 403);
        abort_unless((int) $attachment->ticket_id === (int) $ticket->id, 404);

        if ($attachment->deleted_at === null) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->update(['deleted_at' => now()]);
            $events->record($ticket, $actor, 'attachment.removed', [
                'attachment_id' => $attachment->id,
                'name' => $attachment->original_name,
            ]);
        }

        return redirect()->route('tickets.show', $ticket)->with('success', 'Anexo removido.');
    }

    private function authorizeTicket($actor, Ticket $ticket): void
    {
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);
    }
}
