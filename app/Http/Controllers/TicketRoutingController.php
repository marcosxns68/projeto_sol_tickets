<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
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
     * O fluxo novo usa a mesma regra para departamento + responsável.
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

        if ($targetIsTriage && $departmentChanged && !$request->boolean('confirm_triage')) {
            throw ValidationException::withMessages([
                'department_id' => 'Confirme a devolução para a Triagem. O responsável atual será removido.',
            ]);
        }

        $requestedAssigneeId = $targetIsTriage ? null : ($data['assignee_id'] ?? null);
        $newAssignee = null;
        if ($requestedAssigneeId !== null) {
            $newAssignee = User::query()
                ->whereKey((int) $requestedAssigneeId)
                ->where('active', true)
                ->firstOrFail();
        }

        $oldAssignee = $ticket->assignee;
        $oldAssigneeId = $ticket->assignee_id ? (int) $ticket->assignee_id : null;
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

        if (!$departmentChanged && !$assigneeChanged) {
            return redirect()->route('tickets.show', $ticket)
                ->with('success', 'Departamento e responsável já estavam com esses valores.');
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
            $departmentChanged, $assigneeChanged, $status, $data, $events
        ) {
            $update = [];
            if ($departmentChanged) {
                $update['department_id'] = $target->id;
                $update['status_id'] = $status->id;
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

        $ticket->refresh()->loadMissing(['department', 'requesterUser', 'assignee']);

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

        $message = $targetIsTriage
            ? 'Ticket devolvido para a Triagem. O responsável foi removido.'
            : 'Atendimento atualizado para '.$target->name.($ticket->assignee ? ' · '.$ticket->assignee->name : ' · sem responsável').'.';

        return redirect()->route('tickets.show', $ticket)->with('success', $message);
    }
}
