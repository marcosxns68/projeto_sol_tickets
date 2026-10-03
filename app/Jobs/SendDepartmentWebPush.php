<?php

namespace App\Jobs;

use App\Models\Department;
use App\Models\PwaPushSubscription;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DepartmentAccess;
use App\Services\DepartmentSubscriptions;
use App\Services\PwaWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDepartmentWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 90;

    public function __construct(
        public int $ticketId,
        public int $departmentId,
        public int $userId,
        public string $event,
    ) {}

    public function handle(PwaWebPush $webPush): void
    {
        $ticket = Ticket::find($this->ticketId);
        $user = User::find($this->userId);
        $department = Department::find($this->departmentId);
        if (!$ticket || !$user?->active || !$department ||
            (int) $ticket->department_id !== $this->departmentId ||
            !in_array($this->event, ['created', 'entered', 'replied', 'cancelled'], true)) {
            return;
        }

        if (!app(DepartmentAccess::class)->canView($user, $department)
            || !app(DepartmentSubscriptions::class)->channelEnabled($user, $department, 'push')) {
            return;
        }

        $headline = match ($this->event) {
            'created' => 'Novo ticket em '.$department->name,
            'entered' => 'Ticket encaminhado para '.$department->name,
            'replied' => 'Cliente respondeu em '.$department->name,
            'cancelled' => 'Ticket cancelado em '.$department->name,
        };

        $payload = [
            'title' => $headline,
            'body' => 'Ticket #'.$ticket->number.' · Toque para abrir no Sutoorii Tickets.',
            'url' => route('tickets.show', $ticket, false),
            'tag' => 'tickets-department-'.$ticket->id.'-'.$this->event,
        ];

        foreach (PwaPushSubscription::where('user_id', $user->id)->get() as $device) {
            try {
                $report = $webPush->send($device->subscription, $payload);
                if ($report->isSuccess()) {
                    $device->update(['last_success_at' => now()]);
                } elseif ($report->isSubscriptionExpired()) {
                    $device->delete();
                } else {
                    Log::warning('Falha na entrega Web Push do departamento.', [
                        'ticket_id' => $ticket->id,
                        'department_id' => $department->id,
                        'user_id' => $user->id,
                        'response_status' => $report->getResponse()?->getStatusCode(),
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Falha ao preparar Web Push para dispositivo.', [
                    'ticket_id' => $ticket->id,
                    'department_id' => $department->id,
                    'user_id' => $user->id,
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
