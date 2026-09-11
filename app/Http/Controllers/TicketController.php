<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IntegrationWebhookDispatcher;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index()
    {
        return redirect()->route('boxes.mine');
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->hasPermission('tickets.create'), 403);

        return view('tickets.create', [
            'statuses' => Status::where('active', true)->orderBy('position')->get(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, TicketEventRecorder $events)
    {
        abort_unless($request->user()->hasPermission('tickets.create'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['required', 'date', 'after:now'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $newStatus = Status::system('new') ?? Status::where('category', 'open')->orderBy('position')->first();
        abort_unless($newStatus, 500, 'Nenhum status inicial está configurado.');

        $data += [
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'creator_id' => $request->user()->id,
            'status_id' => $newStatus->id,
        ];

        if (empty($data['department_id'])) {
            $data['department_id'] = $request->user()->department_id;
        }

        $ticket = Ticket::create($data);
        $events->record($ticket, $request->user(), 'created', [
            'department_id' => $ticket->department_id,
            'status' => $newStatus->name,
        ]);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket criado com sucesso.');
    }

    public function show(Request $request, Ticket $ticket)
    {
        $this->ensureVisible($request, $ticket);

        return view('tickets.show', [
            'ticket' => $ticket->load([
                'status', 'assignee', 'creator', 'department', 'participants', 'labels',
                'checklist', 'comments.user', 'events.actor', 'company', 'system',
            ]),
            'statuses' => Status::where('active', true)->orderBy('position')->get(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'users' => User::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function edit(Request $request, Ticket $ticket)
    {
        $this->ensureVisible($request, $ticket);
        return redirect()->route('tickets.show', $ticket);
    }

    public function update(Request $request, Ticket $ticket, TicketEventRecorder $events, IntegrationWebhookDispatcher $webhooks)
    {
        $this->ensureVisible($request, $ticket);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'status_id' => ['required', 'integer', 'exists:statuses,id'],
            'due_at' => ['nullable', 'date'],
        ]);

        $actor = $request->user();
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

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket atualizado.');
    }

    private function ensureVisible(Request $request, Ticket $ticket): void
    {
        abort_unless(Ticket::visibleTo($request->user())->whereKey($ticket->id)->exists(), 403);
    }
}
