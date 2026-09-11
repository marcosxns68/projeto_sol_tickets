<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->visibleQuery($request)
            ->with('status')
            ->orderByDesc('id');

        return response()->json([
            'data' => $query->get()->map(fn (Ticket $ticket) => $this->serializeTicket($ticket))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            'requester_name' => ['required', 'string', 'max:160'],
            'requester_email' => ['nullable', 'email', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        $integration = $this->integration($request);
        $externalUserId = $this->externalUserId($request);

        $existing = Ticket::query()
            ->where('system_id', $integration->id)
            ->where('external_reference', $data['external_reference'])
            ->with('status')
            ->first();

        if ($existing) {
            if (!$this->canAccess($request, $existing)) {
                return response()->json(['message' => 'Referência externa já utilizada.'], 409);
            }

            return response()->json(['ticket' => $this->serializeTicket($existing)]);
        }

        $status = Status::system('new')
            ?? Status::query()->where('active', true)->whereNotIn('category', ['completed', 'cancelled'])->orderBy('position')->first();

        if (!$status) {
            return response()->json(['message' => 'Nenhum status inicial disponível.'], 503);
        }

        $departmentId = Department::query()->where('active', true)->orderBy('id')->value('id');

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'normal',
            'status_id' => $status->id,
            'creator_id' => null,
            'assignee_id' => null,
            'department_id' => $departmentId,
            'company_id' => null,
            'system_id' => $integration->id,
            'requester_name' => $data['requester_name'],
            'requester_email' => $data['requester_email'] ?? null,
            'external_requester_id' => $externalUserId,
            'external_reference' => $data['external_reference'],
        ]);

        $ticket->load('status');

        return response()->json(['ticket' => $this->serializeTicket($ticket)], 201);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);

        return response()->json(['ticket' => $this->serializeTicket($ticket)]);
    }

    public function comment(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $data = $request->validate([
            'body' => ['required', 'string'],
            'external_message_id' => ['nullable', 'string', 'max:190'],
        ]);

        $messageId = null;
        if (!empty($data['external_message_id'])) {
            $messageId = 'integration:'.$this->integration($request)->id.':'.$data['external_message_id'];
            $existing = $ticket->comments()->where('message_id', $messageId)->first();
            if ($existing) {
                return response()->json([
                    'comment' => [
                        'id' => $existing->id,
                        'body' => $existing->body,
                        'created_at' => $existing->created_at?->toIso8601String(),
                    ],
                ]);
            }
        }

        $comment = $ticket->comments()->create([
            'user_id' => null,
            'visibility' => 'public',
            'body' => $data['body'],
            'source' => 'integration',
            'message_id' => $messageId,
        ]);

        return response()->json([
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function attachment(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store('integration-attachments/'.$this->integration($request)->id.'/'.$ticket->id, 'local');

        $id = DB::table('attachments')->insertGetId([
            'ticket_id' => $ticket->id,
            'comment_id' => null,
            'uploaded_by' => null,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'expires_at' => now()->addYear(),
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'attachment' => [
                'id' => $id,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ],
        ], 201);
    }

    public function activity(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $ticket->load('status');

        $comments = $ticket->comments()
            ->where('visibility', 'public')
            ->orderBy('id')
            ->get()
            ->map(fn ($comment) => [
                'type' => 'comment',
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->values();

        return response()->json([
            'ticket' => [
                'number' => $ticket->number,
                'status' => $ticket->status?->name,
                'status_key' => $ticket->status?->system_key,
            ],
            'activity' => $comments,
        ]);
    }

    public function close(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $status = Status::system('closed');

        if (!$status) {
            return response()->json(['message' => 'Status Fechado não configurado.'], 503);
        }

        $ticket->update([
            'status_id' => $status->id,
            'completed_at' => now(),
        ]);
        $ticket->load('status');

        return response()->json(['ticket' => $this->serializeTicket($ticket)]);
    }

    public function reopen(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $status = Status::system('new');

        if (!$status) {
            return response()->json(['message' => 'Status Novo não configurado.'], 503);
        }

        $ticket->update([
            'status_id' => $status->id,
            'completed_at' => null,
        ]);
        $ticket->load('status');

        return response()->json(['ticket' => $this->serializeTicket($ticket)]);
    }

    private function visibleQuery(Request $request): Builder
    {
        $query = Ticket::query()->where('system_id', $this->integration($request)->id);

        if ($this->externalRole($request) !== 'manager') {
            $query->where('external_requester_id', $this->externalUserId($request));
        }

        return $query;
    }

    private function findVisibleTicket(Request $request, string $reference): Ticket
    {
        return $this->visibleQuery($request)
            ->where(function (Builder $query) use ($reference) {
                $query->where('number', $reference)->orWhere('external_reference', $reference);
            })
            ->with('status')
            ->firstOrFail();
    }

    private function canAccess(Request $request, Ticket $ticket): bool
    {
        return $this->externalRole($request) === 'manager'
            || (string) $ticket->external_requester_id === $this->externalUserId($request);
    }

    private function integration(Request $request): ConnectedSystem
    {
        return $request->attributes->get('integration');
    }

    private function externalUserId(Request $request): string
    {
        return (string) $request->attributes->get('external_user_id');
    }

    private function externalRole(Request $request): string
    {
        return (string) $request->attributes->get('external_user_role');
    }

    private function serializeTicket(Ticket $ticket): array
    {
        if (!$ticket->relationLoaded('status')) {
            $ticket->load('status');
        }

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
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }
}
