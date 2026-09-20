<?php

namespace App\Http\Controllers;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Label;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DepartmentAccess;
use App\Services\IntegrationSettings;
use App\Services\PriorityDeadlines;
use App\Services\IntegrationUserDirectory;
use App\Services\IntegrationWebhookDispatcher;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use App\Services\WhatsAppConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TicketController extends Controller
{
    public function index()
    {
        return redirect()->route('boxes.mine');
    }

    public function create(Request $request, DepartmentAccess $departmentAccess)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.create'), 403);

        $sendableIds = $departmentAccess->sendableIds($actor);

        return view('tickets.create', [
            'statuses' => Status::where('active', true)->orderBy('position')->get(),
            'departments' => Department::query()
                ->where('active', true)
                ->whereIn('id', $sendableIds)
                ->orderBy('name')
                ->get(),
            'integrations' => ConnectedSystem::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(
        Request $request,
        TicketEventRecorder $events,
        IntegrationSettings $settings,
        IntegrationUserDirectory $directory,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
        PriorityDeadlines $deadlines,
    ) {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.create'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['nullable', 'date', 'after:now'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'source_mode' => ['nullable', Rule::in(['internal', 'integration'])],
            'system_id' => ['nullable', 'integer'],
            'requester_name' => ['nullable', 'string', 'max:160'],
            'requester_email' => ['nullable', 'email', 'max:190'],
            'requester_whatsapp' => ['nullable', 'string', 'max:35', function ($attribute, $value, $fail) {
                if (filled($value) && WhatsAppConnection::normalizeNumber($value) === null) {
                    $fail('Informe um WhatsApp brasileiro válido com DDD.');
                }
            }],
            'requester_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'integration_target' => ['nullable', Rule::in(['integration', 'external_user'])],
            'external_requester_id' => ['nullable', 'string', 'max:190'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'collaborator_ids' => ['nullable', 'array'],
            'collaborator_ids.*' => ['integer', 'exists:users,id'],
            'follower_ids' => ['nullable', 'array'],
            'follower_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $sourceMode = $data['source_mode'] ?? 'internal';
        $integration = null;
        $externalUser = null;
        $target = null;
        $requesterUser = null;
        $manualRequesterName = trim((string) ($data['requester_name'] ?? '')) ?: null;
        $manualRequesterEmail = $this->normalizeEmail($data['requester_email'] ?? null);

        if (!empty($data['requester_user_id'])) {
            $requesterUser = User::query()
                ->whereKey((int) $data['requester_user_id'])
                ->where('active', true)
                ->firstOrFail();
        }

        if ($sourceMode === 'integration') {
            if (empty($data['system_id'])) {
                throw ValidationException::withMessages(['system_id' => 'Selecione uma integração.']);
            }

            $integration = ConnectedSystem::query()
                ->whereKey((int) $data['system_id'])
                ->where('active', true)
                ->first();

            if (!$integration) {
                throw ValidationException::withMessages(['system_id' => 'A integração selecionada não está disponível.']);
            }

            $target = $data['integration_target'] ?? 'integration';
            if (!in_array($target, ['integration', 'external_user'], true)) {
                throw ValidationException::withMessages(['integration_target' => 'Escolha quem receberá este ticket.']);
            }

            $externalId = trim((string) ($data['external_requester_id'] ?? ''));
            if ($target === 'external_user' && $externalId !== '') {
                try {
                    $externalUser = $directory->find($integration, $externalId);
                } catch (RuntimeException) {
                    throw ValidationException::withMessages([
                        'external_requester_id' => 'Não foi possível validar o usuário da integração agora.',
                    ]);
                }

                if (!$externalUser) {
                    throw ValidationException::withMessages([
                        'external_requester_id' => 'O usuário selecionado não está disponível nesta integração.',
                    ]);
                }
            } elseif ($target === 'external_user' && $manualRequesterEmail === null) {
                throw ValidationException::withMessages([
                    'external_requester_id' => 'Selecione um usuário da integração ou informe o e-mail do solicitante.',
                ]);
            }

            if (!$externalUser && $manualRequesterEmail !== null) {
                try {
                    $matches = $directory->search($integration, $manualRequesterEmail);
                    foreach ($matches as $candidate) {
                        if ($this->normalizeEmail($candidate['email'] ?? null) === $manualRequesterEmail) {
                            $externalUser = $candidate;
                            $target = 'external_user';
                            break;
                        }
                    }
                } catch (RuntimeException) {
                    // O vínculo por e-mail é uma conveniência. Se o diretório estiver
                    // indisponível, o contato manual continua válido.
                }
            }
        }

        $newStatus = Status::system('new') ?? Status::where('category', 'open')->orderBy('position')->first();
        abort_unless($newStatus, 500, 'Nenhum status inicial está configurado.');

        $departmentId = !empty($data['department_id']) ? (int) $data['department_id'] : null;

        if ($integration) {
            $integrationDepartment = $settings->departmentId($integration->id);
            if ($integrationDepartment !== null && Department::query()->whereKey($integrationDepartment)->where('active', true)->exists()) {
                $departmentId = (int) $integrationDepartment;
            }
        }

        if ($departmentId !== null) {
            $department = Department::query()->whereKey($departmentId)->where('active', true)->firstOrFail();
            abort_unless($departmentAccess->canSend($actor, $department), 403);
        }

        if ($departmentId === null && $actor->department_id) {
            $legacyDepartment = Department::query()->whereKey($actor->department_id)->where('active', true)->first();
            if ($legacyDepartment && $departmentAccess->canSend($actor, $legacyDepartment)) {
                $departmentId = $legacyDepartment->id;
            }
        }

        $assignee = null;
        if (!empty($data['assignee_id'])) {
            $assignee = User::query()->whereKey((int) $data['assignee_id'])->where('active', true)->firstOrFail();
        }

        $collaborators = $this->activeUsersByIds($data['collaborator_ids'] ?? []);
        $followers = $this->activeUsersByIds($data['follower_ids'] ?? []);

        $requesterName = $requesterUser?->name
            ?? ($externalUser['name'] ?? null)
            ?? $manualRequesterName;
        $requesterEmail = $this->normalizeEmail(
            $requesterUser?->email
            ?? ($externalUser['email'] ?? null)
            ?? $manualRequesterEmail
        );
        $externalRequesterId = $externalUser['id'] ?? null;

        $ticket = DB::transaction(function () use (
            $actor,
            $data,
            $newStatus,
            $departmentId,
            $integration,
            $requesterUser,
            $requesterName,
            $requesterEmail,
            $externalRequesterId,
            $assignee,
            $collaborators,
            $followers,
            $settings,
            $events,
            $target,
            $deadlines,
        ) {
            $ticket = Ticket::create([
                'number' => Ticket::nextNumber(),
                'origin' => 'internal',
                'title' => $data['title'],
                'description' => $data['description'],
                'priority' => $data['priority'],
                'status_id' => $newStatus->id,
                'creator_id' => $actor->id,
                'assignee_id' => $assignee?->id,
                'department_id' => $departmentId,
                'company_id' => $integration?->company_id,
                'system_id' => $integration?->id,
                'requester_name' => $requesterName,
                'requester_email' => $requesterEmail,
                'requester_whatsapp' => WhatsAppConnection::normalizeNumber($data['requester_whatsapp'] ?? null),
                'requester_user_id' => $requesterUser?->id,
                'external_requester_id' => $externalRequesterId,
                'due_at' => !empty($data['due_at']) ? $data['due_at'] : $deadlines->dueAt($data['priority']),
            ]);

            $participantRows = [];
            foreach ($collaborators as $user) {
                $participantRows[$user->id] = [
                    'type' => 'collaborator',
                    'notify_status' => true,
                    'notify_comments' => true,
                    'notify_attachments' => true,
                ];
            }
            foreach ($followers as $user) {
                if (!isset($participantRows[$user->id])) {
                    $participantRows[$user->id] = [
                        'type' => 'follower',
                        'notify_status' => true,
                        'notify_comments' => true,
                        'notify_attachments' => true,
                    ];
                }
            }
            if ($participantRows !== []) {
                $ticket->participants()->syncWithoutDetaching($participantRows);
            }

            if ($integration) {
                $labelIds = $settings->labelIds($integration->id);
                if ($labelIds !== []) {
                    $ticket->labels()->syncWithoutDetaching($labelIds);
                }
            }

            $events->record($ticket, $actor, 'created', [
                'department_id' => $ticket->department_id,
                'status' => $newStatus->name,
                'integration_id' => $integration?->id,
                'integration_name' => $integration?->name,
                'integration_target' => $target,
                'requester_user_id' => $ticket->requester_user_id,
                'external_requester_id' => $ticket->external_requester_id,
                'external_requester_name' => $requesterName,
                'assignee_id' => $ticket->assignee_id,
            ]);

            return $ticket;
        });

        $ticket->loadMissing(['requesterUser', 'assignee', 'participants', 'department']);
        $notifier->opened($ticket, $actor);

        if (Ticket::visibleTo($actor)->whereKey($ticket->id)->exists()) {
            return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket criado com sucesso.');
        }

        return redirect()->route('boxes.mine')->with('success', 'Ticket criado com sucesso.');
    }

    public function show(Request $request, Ticket $ticket)
    {
        $this->ensureVisible($request, $ticket);

        return view('tickets.show', [
            'ticket' => $ticket->load([
                'status', 'assignee', 'creator', 'requesterUser', 'department', 'participants', 'labels',
                'checklist', 'comments.user', 'events.actor', 'company', 'system', 'attachments.uploader', 'recurrence',
            ]),
            'statuses' => Status::where('active', true)->orderBy('position')->get(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'labels' => Label::query()->orderBy('name')->get(),
        ]);
    }

    public function edit(Request $request, Ticket $ticket)
    {
        $this->ensureVisible($request, $ticket);
        return redirect()->route('tickets.show', $ticket);
    }

    public function update(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        IntegrationWebhookDispatcher $webhooks,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
    ) {
        $this->ensureVisible($request, $ticket);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'status_id' => ['required', 'integer', 'exists:statuses,id'],
            'due_at' => ['nullable', 'date'],
            'notify_requester' => ['nullable', 'boolean'],
            'comment_body' => ['nullable', 'string', 'max:10000'],
            'comment_visibility' => ['nullable', Rule::in(['public', 'internal'])],
            'comment_notify_requester' => ['nullable', 'boolean'],
            'notify_responsible' => ['nullable', 'boolean'],
            'notify_collaborators' => ['nullable', 'boolean'],
            'notify_followers' => ['nullable', 'boolean'],
        ]);

        $actor = $request->user();
        if ($ticket->department_id && !$actor->hasPermission('tickets.view_all')) {
            abort_unless(
                $departmentAccess->canEdit($actor, $ticket->department_id) || $ticket->assignee_id === $actor->id,
                403
            );
        }

        $commentBody = trim((string) ($data['comment_body'] ?? ''));
        if (filled($data['comment_body'] ?? null) && $commentBody === '') {
            throw ValidationException::withMessages(['comment_body' => 'Escreva a mensagem do comentário.']);
        }
        $commentVisibility = $data['comment_visibility'] ?? 'public';
        $isRequester = (int) $ticket->requester_user_id === (int) $actor->id;
        if ($commentBody !== '') {
            $permission = $commentVisibility === 'public' ? 'tickets.comment' : 'tickets.internal_note';
            abort_unless(
                $actor->hasPermission($permission) || ($commentVisibility === 'public' && $isRequester),
                403
            );
        }

        $changes = [];
        if ($data['title'] !== $ticket->title || $data['description'] !== $ticket->description) {
            abort_unless($actor->hasPermission('tickets.edit'), 403);
            $changes['content'] = [
                'old' => ['title' => $ticket->title, 'description' => $ticket->description],
                'new' => ['title' => $data['title'], 'description' => $data['description']],
            ];
        }

        if ($data['priority'] !== $ticket->priority) {
            abort_unless($actor->hasPermission('tickets.change_priority'), 403);
            $changes['priority'] = ['old' => $ticket->priority, 'new' => $data['priority']];
        }

        if ((int) $data['status_id'] !== (int) $ticket->status_id) {
            abort_unless($actor->hasPermission('tickets.change_status'), 403);
            $nextStatus = Status::findOrFail($data['status_id']);
            if (in_array($nextStatus->system_key, ['resolved', 'closed', 'cancelled'], true)) {
                return back()->withErrors([
                    'status_id' => 'Use as ações Resolver, Fechar ou Cancelar para este status.',
                ])->withInput();
            }
            $changes['status'] = ['old' => $ticket->status?->name, 'new' => $nextStatus->name];
        }

        $oldDue = $ticket->due_at?->format('Y-m-d H:i:s');
        $newDue = empty($data['due_at']) ? null : date('Y-m-d H:i:s', strtotime($data['due_at']));
        if ($oldDue !== $newDue) {
            abort_unless($actor->hasPermission('tickets.change_due_date'), 403);
            $changes['due_at'] = ['old' => $oldDue, 'new' => $newDue];
        }

        if ($changes === [] && $commentBody === '') {
            return redirect()->route('tickets.show', $ticket)->with('success', 'Nenhuma alteração para salvar.');
        }

        // Comentário e alterações são persistidos juntos. Se houver erro, nada é salvo.
        [$updateEvent, $comment] = DB::transaction(function () use (
            $ticket, $data, $changes, $events, $actor, $commentBody, $commentVisibility, $isRequester
        ) {
            $updateEvent = null;
            $comment = null;

            if ($changes !== []) {
                $ticket->update([
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'priority' => $data['priority'],
                    'status_id' => $data['status_id'],
                    'due_at' => $data['due_at'] ?: null,
                ]);
                $updateEvent = $events->record($ticket, $actor, 'ticket.updated', ['changes' => $changes]);
            }

            if ($commentBody !== '') {
                $comment = $ticket->comments()->create([
                    'user_id' => $actor->id,
                    'visibility' => $commentVisibility,
                    'body' => $commentBody,
                    'source' => 'web',
                ]);
                $events->record($ticket, $actor,
                    $commentVisibility === 'public' ? 'comment.public' : 'comment.internal',
                    ['comment_id' => $comment->id],
                );

                if ($commentVisibility === 'public' && $isRequester
                    && app(\App\Services\RequesterReplyWorkflow::class)->markRequesterReplied($ticket)) {
                    $events->record($ticket, $actor, 'status.changed', [
                        'source' => 'requester_reply',
                        'automatic' => true,
                        'status' => $ticket->status?->name,
                    ]);
                }
            }

            return [$updateEvent, $comment];
        });

        $ticket->refresh()->load('status');
        $publicComment = $comment && $commentVisibility === 'public';

        if (isset($changes['status'])) {
            $webhooks->dispatch($ticket, 'ticket.status.changed', [
                'previous_status' => $changes['status']['old'],
            ]);
            $notifier->statusChanged($ticket, $actor);
        }

        if ($publicComment) {
            $webhooks->dispatch($ticket, 'ticket.comment.created', [
                'comment' => [
                    'body' => $comment->body,
                    'created_at' => $comment->created_at?->toIso8601String(),
                ],
            ]);
        }

        $notifyRequester = !array_key_exists('notify_requester', $data) || (bool) $data['notify_requester'];
        $commentNotifyRequester = $publicComment && !$isRequester
            && $request->boolean('comment_notify_requester');

        // Uma resposta pública usa a escolha "Notificar solicitante" para ambos
        // os canais, mesmo se o status foi alterado no mesmo salvamento.
        // Não enviar também um segundo e-mail genérico de alteração.
        if ($changes !== [] && $notifyRequester && !$publicComment) {
            $notifier->requesterChanged($ticket, $actor);
        }

        if ($publicComment) {
            $notifier->publicComment($ticket, $actor, [
                'requester' => $commentNotifyRequester,
                'responsible' => $request->boolean('notify_responsible'),
                'collaborators' => $request->boolean('notify_collaborators'),
                'followers' => $request->boolean('notify_followers'),
            ]);
        }

        if ($publicComment) {
            // Com resposta pública, nunca substituir a escolha do operador por
            // uma automação de status disparada no mesmo salvamento.
            if ($commentNotifyRequester) {
                $notifier->publicCommentWhatsApp($ticket, $actor, $comment->id);
            }
        } elseif (isset($changes['status'])) {
            $notifier->statusWhatsAppChanged($ticket, $updateEvent->id);
        }

        return redirect()->route('tickets.show', $ticket)->with(
            'success',
            $comment ? 'Alterações e mensagem salvas com sucesso.' : 'Ticket atualizado.',
        );
    }

    private function activeUsersByIds(array $ids)
    {
        $normalized = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($normalized->isEmpty()) {
            return collect();
        }

        $users = User::query()->whereIn('id', $normalized)->where('active', true)->get();
        if ($users->count() !== $normalized->count()) {
            throw ValidationException::withMessages(['participants' => 'Um dos usuários selecionados não está disponível.']);
        }

        return $users;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        return $normalized !== '' ? $normalized : null;
    }

    private function ensureVisible(Request $request, Ticket $ticket): void
    {
        abort_unless(Ticket::visibleTo($request->user())->whereKey($ticket->id)->exists(), 403);
    }
}
