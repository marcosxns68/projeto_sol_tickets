<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use App\Services\IntegrationSettings;
use App\Services\PriorityDeadlines;
use App\Services\RequesterReplyWorkflow;
use App\Services\TicketNotifier;
use App\Services\WhatsAppConnection;
use App\Services\TicketEventRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IntegrationTicketController extends Controller
{
    /**
     * Recebe o contato do próprio usuário autenticado pelo sistema integrado.
     * Nunca troca telefones existentes nem dispara avisos retroativos.
     */
    public function syncRequesterWhatsApp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requester_whatsapp' => ['required', 'string', 'max:35', function ($attribute, $value, $fail) {
                if (WhatsAppConnection::normalizeNumber($value) === null) {
                    $fail('Informe um WhatsApp brasileiro válido com DDD.');
                }
            }],
        ]);

        $externalId = $this->externalUserId($request);
        if (!preg_match('/^estudio-franca-[1-9][0-9]*$/D', $externalId)) {
            return response()->json(['message' => 'Identificador externo incompatível.'], 422);
        }

        $number = WhatsAppConnection::normalizeNumber($data['requester_whatsapp']);
        $updated = 0;
        Ticket::query()
            ->where('system_id', $this->integration($request)->id)
            ->where('external_requester_id', $externalId)
            ->whereNull('requester_whatsapp')
            ->whereNull('trashed_at')
            ->orderBy('id')
            ->chunkById(100, function ($tickets) use ($number, &$updated): void {
                foreach ($tickets as $ticket) {
                    $ticket->requester_whatsapp = $number;
                    $ticket->save();
                    $updated++;
                }
            });

        return response()->json(['tickets_preenchidos' => $updated]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'status' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->visibleQuery($request)->with(['status', 'labels']);

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['status'])) {
            $status = $filters['status'];
            $query->whereHas('status', function (Builder $builder) use ($status) {
                if ($status === 'cancelled') {
                    $builder->where('category', 'cancelled');
                } else {
                    $builder->where('system_key', $status)->orWhere('name', $status);
                }
            });
        }

        // A caixa de entrada do sistema integrado omite cancelados por padrão,
        // mas permite consultá-los explicitamente sem afetar a paginação.
        if (empty($filters['status'])) {
            $query->whereHas('status', fn (Builder $builder) => $builder->where('category', '!=', 'cancelled'));
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);
        $paginator = $query->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Ticket $ticket) => $this->serializeTicket($ticket))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, IntegrationSettings $settings, TicketNotifier $notifier, PriorityDeadlines $deadlines): JsonResponse
    {
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:255'],
            'requester_name' => ['required', 'string', 'max:160'],
            'requester_email' => ['nullable', 'email', 'max:255'],
            'requester_whatsapp' => ['nullable', 'string', 'max:35', function ($attribute, $value, $fail) {
                if (filled($value) && WhatsAppConnection::normalizeNumber($value) === null) {
                    $fail('Informe um WhatsApp brasileiro válido com DDD.');
                }
            }],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        $integration = $this->integration($request);
        $externalUserId = $this->externalUserId($request);
        $externalReference = isset($data['external_reference']) ? trim((string) $data['external_reference']) : null;
        $externalReference = $externalReference === '' ? null : $externalReference;

        if ($externalReference !== null) {
            $existing = Ticket::query()
                ->where('system_id', $integration->id)
                ->where('external_reference', $externalReference)
                ->with(['status', 'labels'])
                ->first();

            if ($existing) {
                if (!$this->canAccess($request, $existing)) {
                    return response()->json(['message' => 'Referência externa já utilizada.'], 409);
                }

                return response()->json(['ticket' => $this->serializeTicket($existing)]);
            }
        }

        $status = Status::system('new')
            ?? Status::query()->where('active', true)->whereNotIn('category', ['completed', 'cancelled'])->orderBy('position')->first();

        if (!$status) {
            return response()->json(['message' => 'Nenhum status inicial disponível.'], 503);
        }

        $departmentId = $settings->departmentId($integration->id);
        if ($departmentId !== null && !Department::query()->whereKey($departmentId)->where('active', true)->exists()) {
            $departmentId = null;
        }
        $departmentId ??= Department::query()->where('active', true)->orderBy('id')->value('id');

        $create = function () use ($data, $integration, $externalUserId, $externalReference, $status, $departmentId, $deadlines): Ticket {
            return Ticket::create([
                'number' => Ticket::nextNumber(),
                'origin' => 'integration',
                'title' => $data['title'],
                'description' => $data['description'],
                'priority' => $data['priority'] ?? 'normal',
                'due_at' => $deadlines->dueAt($data['priority'] ?? 'normal'),
                'status_id' => $status->id,
                'creator_id' => null,
                'assignee_id' => null,
                'department_id' => $departmentId,
                'company_id' => null,
                'system_id' => $integration->id,
                'requester_name' => $data['requester_name'],
                'requester_email' => $data['requester_email'] ?? null,
                'requester_whatsapp' => WhatsAppConnection::normalizeNumber($data['requester_whatsapp'] ?? null),
                'external_requester_id' => $externalUserId,
                'external_reference' => $externalReference,
            ]);
        };

        $created = true;
        if ($externalReference === null) {
            $ticket = $create();
        } else {
            $idempotencyKey = 'integration.idempotency.'.$integration->id.'.'.hash('sha256', $externalReference);
            $result = DB::transaction(function () use ($idempotencyKey, $integration, $externalReference, $create) {
                DB::table('settings')->insertOrIgnore([
                    'key' => $idempotencyKey,
                    'value' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('settings')->where('key', $idempotencyKey)->lockForUpdate()->first();
                if ($row && is_string($row->value) && str_starts_with($row->value, 'ticket:')) {
                    $ticketId = (int) substr($row->value, 7);
                    $existing = Ticket::query()->where('system_id', $integration->id)->find($ticketId);
                    if ($existing) {
                        return [$existing, false];
                    }
                }

                $existing = Ticket::query()
                    ->where('system_id', $integration->id)
                    ->where('external_reference', $externalReference)
                    ->first();

                if ($existing) {
                    DB::table('settings')->where('key', $idempotencyKey)->update([
                        'value' => 'ticket:'.$existing->id,
                        'updated_at' => now(),
                    ]);
                    return [$existing, false];
                }

                $ticket = $create();
                DB::table('settings')->where('key', $idempotencyKey)->update([
                    'value' => 'ticket:'.$ticket->id,
                    'updated_at' => now(),
                ]);

                return [$ticket, true];
            });

            [$ticket, $created] = $result;
            if (!$created) {
                $ticket->load(['status', 'labels']);
                if (!$this->canAccess($request, $ticket)) {
                    return response()->json(['message' => 'Referência externa já utilizada.'], 409);
                }
                return response()->json(['ticket' => $this->serializeTicket($ticket)]);
            }
        }

        if ($created) {
            $labelIds = $settings->labelIds($integration->id);
            if ($labelIds !== []) {
                $ticket->labels()->syncWithoutDetaching($labelIds);
            }
        }

        $ticket->load(['status', 'labels']);

        if ($created) {
            $notifier->opened($ticket, null);
        }

        return response()->json(['ticket' => $this->serializeTicket($ticket)], 201);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);

        return response()->json(['ticket' => $this->serializeTicket($ticket)]);
    }

    public function comment(Request $request, string $reference, TicketNotifier $notifier, RequesterReplyWorkflow $replyWorkflow, TicketEventRecorder $events): JsonResponse
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

        if ($replyWorkflow->markRequesterReplied($ticket)) {
            $events->record($ticket, null, 'status.changed', [
                'source' => 'requester_reply',
                'automatic' => true,
                'status' => $ticket->status?->name,
            ]);
        }

        $notifier->publicComment($ticket, null, [
            'requester' => false,
            'responsible' => true,
            'collaborators' => true,
            'followers' => true,
        ], $ticket->requester_email);

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
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,txt,doc,docx,xls,xlsx,zip', 'max:10240'],
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

    public function downloadAttachment(Request $request, string $reference, int $attachment)
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $row = DB::table('attachments')
            ->where('id', $attachment)
            ->where('ticket_id', $ticket->id)
            ->whereNull('deleted_at')
            ->first();

        abort_unless($row, 404);
        abort_if($row->expires_at && now()->greaterThanOrEqualTo($row->expires_at), 404);
        abort_unless(Storage::disk($row->disk)->exists($row->path), 404);

        return Storage::disk($row->disk)->download($row->path, $row->original_name);
    }

    public function activity(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $ticket->load('status');

        $comments = $ticket->comments()
            ->with('user')
            ->where('visibility', 'public')
            ->orderBy('id')
            ->get()
            ->map(function ($comment) use ($ticket) {
                $fromRequester = $comment->source === 'integration' && $comment->user_id === null;
                $authorName = $comment->user?->name
                    ?: ($fromRequester ? ($ticket->requester_name ?: 'Solicitante') : 'Equipe de suporte');

                return [
                    'type' => 'comment',
                    'body' => $comment->body,
                    'author_name' => $authorName,
                    'author_type' => $fromRequester ? 'requester' : 'support',
                    'created_at' => $comment->created_at?->toIso8601String(),
                ];
            })->values();

        $attachments = DB::table('attachments')
            ->where('ticket_id', $ticket->id)
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->get()
            ->map(fn ($attachment) => [
                'type' => 'attachment',
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'created_at' => $attachment->created_at,
            ]);

        return response()->json([
            'ticket' => [
                'number' => $ticket->number,
                'status' => $ticket->status?->name,
                'status_key' => $ticket->status?->system_key,
            ],
            'activity' => $comments->concat($attachments)->values(),
        ]);
    }

    public function close(Request $request, string $reference, TicketNotifier $notifier): JsonResponse
    {
        $ticket = $this->findVisibleTicket($request, $reference);
        $status = Status::system('closed');

        if (!$status) {
            return response()->json(['message' => 'Status Fechado não configurado.'], 503);
        }

        $wasClosed = $ticket->status?->system_key === 'closed';
        $ticket->update([
            'status_id' => $status->id,
            'completed_at' => now(),
        ]);
        $ticket->load(['status', 'labels']);
        if (!$wasClosed) {
            $notifier->closed($ticket);
        }

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
        $ticket->load(['status', 'labels']);

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
            ->with(['status', 'labels'])
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
        if (!$ticket->relationLoaded('status') || !$ticket->relationLoaded('labels')) {
            $ticket->loadMissing(['status', 'labels']);
        }

        $lastPublicReply = $ticket->comments()
            ->where('visibility', 'public')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $lastReplyBy = $lastPublicReply
            ? (($lastPublicReply->source === 'integration' || $lastPublicReply->source === 'requester'
                || ($ticket->requester_user_id && (int) $lastPublicReply->user_id === (int) $ticket->requester_user_id))
                ? 'requester' : 'support')
            : null;

        return [
            'number' => $ticket->number,
            'external_reference' => $ticket->external_reference,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'due_at' => $ticket->due_at?->toIso8601String(),
            'status' => $ticket->status?->name,
            'status_key' => $ticket->status?->system_key,
            'last_public_reply_by' => $lastReplyBy,
            'last_public_reply_at' => $lastPublicReply?->created_at?->toIso8601String(),
            'labels' => $ticket->labels->sortBy('name')->values()->map(fn ($label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ])->all(),
            'requester_name' => $ticket->requester_name,
            'requester_email' => $ticket->requester_email,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }
}
