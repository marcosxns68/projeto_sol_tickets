<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TicketBoxController extends Controller
{
    public function mine(Request $request)
    {
        $query = Ticket::query()->myBox($request->user());
        $this->applyFilters($query, $request, true);

        return view('boxes.index', [
            'tickets' => $query->with(['status', 'assignee', 'department', 'labels'])->latest()->paginate(20)->withQueryString(),
            'statuses' => Status::orderBy('position')->get(),
            'boxTitle' => 'Minha Caixa',
            'boxKind' => 'mine',
            'department' => null,
        ]);
    }

    public function all(Request $request)
    {
        abort_unless($request->user()->hasPermission('tickets.view_all'), 403);

        $query = Ticket::query();
        $this->applyFilters($query, $request, false);

        return view('boxes.index', [
            'tickets' => $query->with(['status', 'assignee', 'department', 'labels'])->latest()->paginate(20)->withQueryString(),
            'statuses' => Status::orderBy('position')->get(),
            'boxTitle' => 'Todos os tickets',
            'boxKind' => 'all',
            'department' => null,
        ]);
    }

    public function department(Request $request, Department $department)
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('tickets.view_all') ||
            ($user->department_id === $department->id && $user->hasPermission('tickets.view_department')),
            403
        );

        $query = Ticket::query()->where('department_id', $department->id);
        $this->applyFilters($query, $request, false);

        return view('boxes.index', [
            'tickets' => $query->with(['status', 'assignee', 'department', 'labels'])->latest()->paginate(20)->withQueryString(),
            'statuses' => Status::orderBy('position')->get(),
            'boxTitle' => 'Caixa: '.$department->name,
            'boxKind' => 'department',
            'department' => $department,
        ]);
    }

    private function applyFilters(Builder $query, Request $request, bool $mine): void
    {
        if ($request->filled('status')) {
            $query->whereNull('trashed_at')->whereHas('status', fn (Builder $status) =>
                $status->where('system_key', $request->string('status')->toString())
                    ->orWhere('id', $request->input('status'))
            );
        } else {
            $query->activeForBox();
        }

        if ($mine && $request->filled('relation')) {
            $relation = $request->string('relation')->toString();
            if ($relation === 'assignee') {
                $query->where('assignee_id', $request->user()->id);
            } elseif (in_array($relation, ['collaborator', 'follower'], true)) {
                $query->whereHas('participants', fn (Builder $participants) =>
                    $participants->where('users.id', $request->user()->id)
                        ->where('ticket_participants.type', $relation)
                );
            }
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority')->toString());
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('assignee_id');
        }

        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_at')->where('due_at', '<', now());
        }

        if ($request->filled('q')) {
            $term = trim($request->string('q')->toString());
            $query->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
            );
        }
    }
}
