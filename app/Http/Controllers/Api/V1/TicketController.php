<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Status;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use Illuminate\Http\Request;

class TicketController extends BaseIntegrationController
{
    public function index(Request $request)
    {
        $query = $this->scopedTickets($request)->with('status')->latest('id');

        if ($request->filled('priority')) {
            $request->validate(['priority' => ['in:low,normal,high,urgent']]);
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            $query->whereHas('status', fn ($q) => $q->where('system_key', $status)->orWhere('name', $status));
        }

        $tickets = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($tickets->items())->map(fn (Ticket $ticket) => $this->ticketPayload($ticket))->values(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function store(Request $request, TicketEventRecorder $events)
    {
        $integration = $this->integration($request);
        $externalUserId = (string) $request->attributes->get('external_user_id');

        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:190'],
            'requester_name' => ['required', 'string', 'max:160'],
            'requester_email' => ['nullable', 'email', 'max:190'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'metadata' => ['nullable', 'array', 'max:20'],
        ]);

        if (!empty($data['external_reference'])) {
            $existing = Ticket::where('system_id', $integration->id)
                ->where('external_reference', $data['external_reference'])
                ->first();

            if ($existing) {
                $visible = $request->attributes->get('external_user_role') === 'manager'
                    || (string) $existing->external_requester_id === $externalUserId;
                abort_unless($visible, 409, 'Referência externa já utilizada.');
                return response()->json(['ticket' => $this->ticketPayload($existing)], 200);
            }
        }

        $status = Status::system('new');
        abort_unless($status, 500, 'Status Novo não configurado.');

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'priority' => $data['priority'] ?? 'normal',
            'status_id' => $status->id,
            'department_id' => $integration->department_id,
            'company_id' => null,
            'system_id' => $integration->id,
            'requester_name' => trim($data['requester_name']),
            'requester_email' => $data['requester_email'] ?? null,
            'external_requester_id' => $externalUserId,
            'external_reference' => $data['external_reference'] ?? null,
            'external_metadata' => $data['metadata'] ?? null,
        ]);

        $events->record($ticket, null, 'created.integration', [
            'external_user_id' => $externalUserId,
            'integration_id' => $integration->id,
        ]);

        return response()->json(['ticket' => $this->ticketPayload($ticket)], 201);
    }

    public function show(Request $request, string $number)
    {
        return response()->json(['ticket' => $this->ticketPayload($this->ticket($request, $number))]);
    }
}
