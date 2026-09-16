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
use App\Services\IntegrationUserDirectory;
use App\Services\IntegrationWebhookDispatcher;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
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
    ) {
        $actor = $request->user();
        abort_unless($actor->hasPermission('tickets.create'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['required', 'date', 'after:now'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'source_mode' => ['nullable', Rule::in(['internal', 'integration'])],
            'system_id' => ['nullable', 'integer'],
            'requester_name' => ['nullable', 'string', 'max:160'],
            'requester_email' => ['nullable', 'email', 'max:190'],
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
        $departmentFromIntegrationDefault = false;

        if ($integration) {
            $integrationDepartment = $settings->departmentId($integration->id);
            if ($integrationDepartment !== null && Department::query()->whereKey($integrationDepartment)->where('active', true)->exists()) {
                $departmentId = (int) $integrationDepartment;
                $departmentFromIntegrationDefault = true;
            }
        }

        if ($departmentId !== null && !$departmentFromIntegrationDefault) {
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
                'requester_user_id' => $requesterUser?->id,
                'external_requester_id' => $externalRequesterId,
                'due_at' => $data['due_at'],
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
                'checklist', 'comments.user', 'events.actor', 'company', 'system',
            ]),
            'statuses' => Status::where('active', true)->orderBy('position')->get(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'users' => User::where('active', true)->orderBy('name')->get(),
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
        ]);

        $actor = $request->user();
        if ($ticket->department_id && !$actor->hasPermission('tickets.view_all')) {
            abort_unless($departmentAccess->canEdit($actor, $ticket->department_id) || $ticket->assignee_id === $actor->id, 403);
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
                return back()->withErrors(['status_id' => 'Use as ações Resolver, Fechar ou Cancelar para este status.'])->withInput();
            }
            $changes['status'] = ['old' => $ticket->status?->name, 'new' => $nextStatus->name];
        }

        $oldDue = $ticket->due_at?->format('Y-m-d H:i:s');
        $newDue = empty($data['due_at']) ? null : date('Y-m-d H:i:s', strtotime($data['due_at']));
        if ($oldDue !== $newDue) {
            abort_unless($actor->hasPermission('tickets.change_due_date'), 403);
            $changes['due_at'] = ['old' => $oldDue, 'new' => $newDue];
        }

        DB::transaction(function () use ($ticket, $data, $changes, $events, $actor) {
            $ticket->update([
                'title' => $data['title'],
                'description' => $data['description'],
                'priority' => $data['priority'],
                'status_id' => $data['status_id'],
                'due_at' => $data['due_at'] ?: null,
            ]);

            if ($changes) {
                $events->record($ticket, $actor, 'ticket.updated', ['changes' => $changes]);
            }
        });

        if (isset($changes['status'])) {
            $ticket->refresh()->load('status');
            $webhooks->dispatch($ticket, 'ticket.status.changed', [
                'previous_status' => $changes['status']['old'],
            ]);
        }

        $notifyRequester = !array_key_exists('notify_requester', $data) || (bool) $data['notify_requester'];
        if ($changes !== [] && $notifyRequester) {
            $ticket->refresh()->loadMissing('requesterUser');
            $notifier->requesterChanged($ticket, $actor);
        }

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket atualizado.');
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
