<?php

namespace App\Console\Commands;

use App\Models\Recurrence;
use App\Models\Status;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessTicketRecurrences extends Command
{
    protected $signature = 'tickets:process-recurrences';
    protected $description = 'Gera novos tickets a partir das recorrências vencidas';

    public function handle(TicketEventRecorder $events, TicketNotifier $notifier): int
    {
        $ids = Recurrence::query()
            ->where('active', true)
            ->where('next_run_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderBy('next_run_at')
            ->pluck('id');

        foreach ($ids as $id) {
            $created = DB::transaction(function () use ($id, $events) {
                $recurrence = Recurrence::query()->whereKey($id)->lockForUpdate()->first();
                if (!$recurrence || !$recurrence->active || $recurrence->next_run_at->isFuture()) {
                    return null;
                }

                if ($recurrence->ends_at && $recurrence->next_run_at->greaterThan($recurrence->ends_at)) {
                    $recurrence->update(['active' => false]);
                    return null;
                }

                $source = Ticket::query()->with(['checklist', 'labels'])->find($recurrence->source_ticket_id);
                if (!$source) {
                    $recurrence->update(['active' => false]);
                    return null;
                }

                $status = Status::system('new') ?? Status::query()->where('category', 'open')->orderBy('position')->first();
                if (!$status) {
                    $this->error('Nenhum status inicial configurado para gerar recorrências.');
                    return null;
                }

                $dueAt = null;
                if ($source->due_at && $source->created_at) {
                    $seconds = max(0, $source->due_at->getTimestamp() - $source->created_at->getTimestamp());
                    if ($seconds > 0) {
                        $dueAt = now()->addSeconds($seconds);
                    }
                }

                $ticket = Ticket::create([
                    'number' => Ticket::nextNumber(),
                    'origin' => $source->origin,
                    'title' => $source->title,
                    'description' => $source->description,
                    'priority' => $source->priority,
                    'status_id' => $status->id,
                    'creator_id' => $source->creator_id,
                    'assignee_id' => null,
                    'department_id' => $source->department_id ?? Department::triage()->id,
                    'company_id' => $source->company_id,
                    'system_id' => $source->system_id,
                    'requester_name' => $source->requester_name,
                    'requester_email' => $source->requester_email,
                    'requester_whatsapp' => $source->requester_whatsapp,
                    'requester_user_id' => $source->requester_user_id,
                    'external_requester_id' => $source->external_requester_id,
                    'external_reference' => null,
                    'due_at' => $dueAt,
                ]);

                foreach ($source->checklist as $item) {
                    $ticket->checklist()->create([
                        'text' => $item->text,
                        'required' => $item->required,
                        'position' => $item->position,
                        'completed' => false,
                    ]);
                }

                $ticket->labels()->sync($source->labels->pluck('id')->all());
                $events->record($ticket, null, 'recurrence_created', [
                    'source_ticket_id' => $source->id,
                    'source_ticket_number' => $source->number,
                    'recurrence_id' => $recurrence->id,
                ]);

                $next = $this->nextRun($recurrence);
                $active = !$recurrence->ends_at || $next->lessThanOrEqualTo($recurrence->ends_at);
                $recurrence->update([
                    'next_run_at' => $next,
                    'active' => $active,
                ]);

                return $ticket;
            });

            if ($created) {
                $created->loadMissing(['requesterUser', 'assignee', 'participants', 'department']);
                $notifier->opened($created, null);
            }
        }

        return self::SUCCESS;
    }

    private function nextRun(Recurrence $recurrence)
    {
        $from = $recurrence->next_run_at->copy();
        $interval = max(1, (int) $recurrence->interval);

        if ($recurrence->frequency === 'daily') {
            return $from->addDays($interval);
        }

        if ($recurrence->frequency === 'monthly') {
            return $from->addMonthsNoOverflow($interval);
        }

        $weekdays = collect($recurrence->weekdays ?: [$from->dayOfWeek])
            ->map(fn ($day) => (int) $day)
            ->filter(fn ($day) => $day >= 0 && $day <= 6)
            ->unique()
            ->sort()
            ->values();

        $laterThisCycle = $weekdays->first(fn ($day) => $day > $from->dayOfWeek);
        if ($laterThisCycle !== null) {
            return $from->addDays($laterThisCycle - $from->dayOfWeek);
        }

        $first = (int) ($weekdays->first() ?? $from->dayOfWeek);
        $days = (7 - $from->dayOfWeek) + $first + (7 * ($interval - 1));
        return $from->addDays(max(1, $days));
    }
}
