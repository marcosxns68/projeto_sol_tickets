<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Label;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\TicketEventRecorder;
use App\Services\TicketNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TicketMaintenance extends Command
{
    protected $signature = 'tickets:maintenance';
    protected $description = 'Atualiza atrasos, avisa prazos e executa retenções automáticas';

    public function handle(TicketNotifier $notifier, TicketEventRecorder $events): int
    {
        $this->syncOverdueLabel();
        $this->notifyUpcomingDeadlines($notifier, $events);
        $this->expireAttachments();
        $this->purgeTrash();

        return self::SUCCESS;
    }

    private function syncOverdueLabel(): void
    {
        $label = Label::query()->firstOrCreate(
            ['name' => 'Atrasada'],
            ['color' => '#DC2626', 'system' => true],
        );

        if (!$label->system) {
            $label->update(['system' => true]);
        }

        $overdueIds = Ticket::query()
            ->whereNull('trashed_at')
            ->whereNull('completed_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereHas('status', fn ($query) => $query->whereNotIn('category', ['completed', 'cancelled']))
            ->pluck('id');

        foreach ($overdueIds as $ticketId) {
            $label->tickets()->syncWithoutDetaching([$ticketId]);
        }

        $staleIds = $label->tickets()
            ->when($overdueIds->isNotEmpty(), fn ($query) => $query->whereNotIn('tickets.id', $overdueIds))
            ->when($overdueIds->isEmpty(), fn ($query) => $query)
            ->pluck('tickets.id');

        if ($staleIds->isNotEmpty()) {
            $label->tickets()->detach($staleIds);
        }
    }

    private function notifyUpcomingDeadlines(TicketNotifier $notifier, TicketEventRecorder $events): void
    {
        $tickets = Ticket::query()
            ->whereNull('trashed_at')
            ->whereNull('completed_at')
            ->whereBetween('due_at', [now(), now()->addDay()])
            ->whereHas('status', fn ($query) => $query->whereNotIn('category', ['completed', 'cancelled']))
            ->whereDoesntHave('events', function ($query) {
                $query->where('event', 'deadline.reminder')
                    ->whereDate('created_at', today());
            })
            ->get();

        foreach ($tickets as $ticket) {
            $notifier->deadlineApproaching($ticket);
            $events->record($ticket, null, 'deadline.reminder', [
                'due_at' => $ticket->due_at?->toIso8601String(),
            ]);
        }
    }

    private function expireAttachments(): void
    {
        Attachment::query()
            ->whereNull('deleted_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($attachments) {
                foreach ($attachments as $attachment) {
                    Storage::disk($attachment->disk)->delete($attachment->path);
                    $attachment->update(['deleted_at' => now()]);
                }
            });
    }

    private function purgeTrash(): void
    {
        $retentionDays = max(1, (int) Setting::getValue('trash.retention_days', 30));
        Ticket::query()
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', now()->subDays($retentionDays))
            ->orderBy('id')
            ->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    foreach ($ticket->attachments()->whereNull('deleted_at')->get() as $attachment) {
                        Storage::disk($attachment->disk)->delete($attachment->path);
                    }
                    $ticket->delete();
                }
            });
    }
}
