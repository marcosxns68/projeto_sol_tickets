<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketFolder;
use App\Models\User;
use App\Services\DepartmentAccess;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketRoutingController extends Controller
{
    /**
     * Compatibilidade com a rota antiga de encaminhamento.
     * O fluxo novo usa a mesma regra para departamento + responsável + pasta.
     */
    public function forward(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
    ) {
        return $this->applyRouting($request, $ticket, $events, $departmentAccess, $notifier);
    }

    public function update(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
    ) {
        return $this->applyRouting($request, $ticket, $events, $departmentAccess, $notifier);
    }

    private function applyRouting(
        Request $request,
        Ticket $ticket,
        TicketEventRecorder $events,
        DepartmentAccess $departmentAccess,
        TicketNotifier $notifier,
    ) {
        $actor = $request->user();
        abort_unless(Ticket::visibleTo($actor)->whereKey($ticket->id)->exists(), 403);

        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'folder_id' => ['nullable', 'integer', 'exists:ticket_folders,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notify_requester' => ['nullable', 'boolean'],
            'confirm_triage' => ['nullable', 'boolean'],
        ]);

        $target = Department::query()->whereKey((int) $data['department_id'])->where('active', true)->firstOrFail();
        $targetIsTriage = $target->isTriage();
        $source = $ticket->department;
        $sourceId = $ticket->department_id ? (int) $ticket->department_id : null;
        $departmentChanged = $sourceId !== (int) $target->id;

        if ($departmentChanged) {
            abort_unless($actor->hasPermission('tickets.forward'), 403);

            // A caixa padrão sempre pode receber devoluções por quem possui
            // permissão de encaminhamento. Departamentos comuns continuam
            // respeitando a permissão de envio de cada pessoa.
            if (!$targetIsTriage) {
                abort_unless($departmentAccess->canSend($actor, $target), 403);
            }

            if ($sourceId && !$source?->isTriage() && !$actor->hasPermission('tickets.view_all')) {
                abort_unless(
                    $departmentAccess->canEdit($actor, $sourceId) || (int) $ticket->assignee_id === (int) $actor->id,
                    403
                );
            }
        }

        $folderSubmitted = $request->has('folder_id');
        $oldFolder = $ticket->folder;
        $oldFolderId = $ticket->folder_id ? (int) $ticket->folder_id : null;
        $newFolder = null;
        $newFolderId = $oldFolderId;

        if ($targetIsTriage) {
            if ($folderSubmitted && !empty($data['folder_id'])) {
                throw ValidationException::withMessages([
                    'folder_id' => 'A Triagem não possui pastas.',
                ]);
            }
            $newFolderId = null;
        } elseif ($folderSubmitted) {
            if (!empty($data['folder_id'])) {
                $newFolder = TicketFolder::query()->find((int) $data['folder_id']);
                if (!$newFolder || !$newFolder->belongsToDepartment($target)) {
                    throw ValidationException::withMessages([
                        'folder_id' => 'A pasta selecionada não pertence ao departamento de destino.',
                    ]);
                }

                // A estrutura interna só pode ser alterada por quem possui o
                // Nível 3 — Editar naquele departamento. Enviar/View não
                // permitem forçar uma pasta por request.
                abort_unless($departmentAccess->canEdit($actor, $target), 403);
                $newFolderId = (int) $newFolder->id;
            } else {
                $newFolderId = null;
            }
        } elseif ($departmentChanged) {
            // Compatibilidade com fluxos antigos: ao trocar de departamento
            // sem informar pasta, sempre cai na raiz do novo departamento.
            $newFolderId = null;
        }

        $folderChanged = $oldFolderId !== $newFolderId;
        if ($folderChanged && !$departmentChanged && $sourceId) {
            abort_unless($departmentAccess->canEdit($actor, $sourceId), 403);
        }

        if ($targetIsTriage && $departmentChanged && !$request->boolean('confirm_triage')) {
            throw ValidationException::withMessages([
                'department_id' => 'Confirme a devolução para a Triagem. O responsável atual será removido.',
            ]);
        }

        $oldAssignee = $ticket->assignee;
        $oldAssigneeId = $ticket->assignee_id ? (int) $ticket->assignee_id : null;
        $assigneeSubmitted = $request->has('assignee_id');

        if ($targetIsTriage) {
            $requestedAssigneeId = null;
        } elseif ($departmentChanged) {
            // Encaminhar continua removendo o responsável se outro não for
            // escolhido explicitamente.
            $requestedAssigneeId = $data['assignee_id'] ?? null;
        } elseif ($assigneeSubmitted) {
            $requestedAssigneeId = $data['assignee_id'] ?? null;
        } else {
            // Alterar apenas a pasta não pode apagar silenciosamente o
            // responsável atual.
            $requestedAssigneeId = $oldAssigneeId;
        }

        $newAssignee = null;
        if ($requestedAssigneeId !== null) {
            $newAssignee = User::query()
                ->whereKey((int) $requestedAssigneeId)
                ->where('active', true)
                ->firstOrFail();
        }

        $newAssigneeId = $newAssignee?->id;
        $assigneeChanged = $oldAssigneeId !== $newAssigneeId;

        if ($assigneeChanged && !$departmentChanged && $ticket->department_id && !$actor->hasPermission('tickets.view_all')) {
            abort_unless($departmentAccess->canEdit($actor, $ticket->department_id), 403);
        }

        if ($assigneeChanged && !$targetIsTriage) {
            // Remover o responsável ao encaminhar sem escolher outro continua
            // fazendo parte do encaminhamento. Escolher/trocar uma pessoa exige
            // a permissão específica de reatribuição.
            $requiresReassignPermission = !$departmentChanged || $newAssignee !== null;
            if ($requiresReassignPermission) {
                abort_unless($actor->hasPermission('tickets.reassign'), 403);
            }
        }

        if (!$departmentChanged && !$assigneeChanged && !$folderChanged) {
            return redirect()->route('tickets.show', $ticket)
                ->with('success', 'Departamento, pasta e responsável já estavam com esses valores.');
        }

        $status = null;
        if ($departmentChanged) {
            $status = $targetIsTriage
                ? Status::system('new')
                : Status::system('forwarded');
            abort_unless($status, 500, 'Status de roteamento não configurado.');
        }

        DB::transaction(function () use (
            $ticket, $actor, $source, $target, $oldAssignee, $newAssignee,
            $departmentChanged, $assigneeChanged, $folderChanged, $newFolderId,
            $status, $data, $events
        ) {
            $update = [];
            if ($departmentChanged) {
                $update['department_id'] = $target->id;
                $update['status_id'] = $status->id;
                $update['folder_id'] = $newFolderId;
            } elseif ($folderChanged) {
                $update['folder_id'] = $newFolderId;
            }
            if ($assigneeChanged || $target->isTriage()) {
                $update['assignee_id'] = $target->isTriage() ? null : $newAssignee?->id;
            }
            $ticket->update($update);

            if ($departmentChanged) {
                $events->record($ticket, $actor, $target->isTriage() ? 'triage.returned' : 'forwarded', [
                    'from_department_id' => $source?->id,
                    'from_department_name' => $source?->name,
                    'to_department_id' => $target->id,
                    'to_department_name' => $target->name,
                    'reason' => $data['reason'] ?? null,
                ]);
            }

            if ($assigneeChanged) {
                $events->record($ticket, $actor, 'reassigned', [
                    'old_assignee_id' => $oldAssignee?->id,
                    'old_assignee_name' => $oldAssignee?->name,
                    'new_assignee_id' => $target->isTriage() ? null : $newAssignee?->id,
                    'new_assignee_name' => $target->isTriage() ? null : $newAssignee?->name,
                ]);
            }
        });

        $ticket->refresh()->loadMissing(['department', 'folder', 'requesterUser', 'assignee']);

        if ($assigneeChanged) {
            $notifier->reassigned($ticket, $oldAssignee, $ticket->assignee, $actor);
        }
        if ($departmentChanged) {
            $notifier->departmentEvent($ticket, 'entered', $actor);
        }
        if ((!array_key_exists('notify_requester', $data) || (bool) $data['notify_requester']) && $departmentChanged) {
            $notifier->requesterChanged(
                $ticket,
                $actor,
                $targetIsTriage ? 'Ticket devolvido para triagem' : 'Ticket encaminhado'
            );
        }

        if (!$departmentChanged && $folderChanged && !$assigneeChanged) {
            $location = $ticket->folder?->name ?? 'Raiz do departamento';
            return redirect()->route('tickets.show', $ticket)
                ->with('success', 'Localização do ticket atualizada: '.$location.'.');
        }

        $message = $targetIsTriage
            ? 'Ticket devolvido para a Triagem. O responsável foi removido.'
            : 'Atendimento atualizado para '.$target->name.($ticket->assignee ? ' · '.$ticket->assignee->name : ' · sem responsável').'.';

        return redirect()->route('tickets.show', $ticket)->with('success', $message);
    }
}
