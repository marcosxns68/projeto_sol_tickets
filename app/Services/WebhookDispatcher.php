<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    public function __construct(private WebhookDeliveryProcessor $processor) {}

    public function queue(Ticket $ticket, string $event, array $payload = []): ?WebhookDelivery
    {
        if ($ticket->origin !== 'integration' || !$ticket->system_id) {
            return null;
        }

        $integration = $ticket->system;
        if (!$integration || !$integration->active || !$integration->webhook_url) {
            return null;
        }

        $ticket->loadMissing('status');
        $delivery = WebhookDelivery::create([
            'system_id' => $integration->id,
            'ticket_id' => $ticket->id,
            'delivery_uuid' => (string) Str::uuid(),
            'event' => $event,
            'payload' => array_merge([
                'event' => $event,
                'ticket_number' => $ticket->number,
                'external_reference' => $ticket->external_reference,
                'status' => $ticket->status?->name,
                'occurred_at' => now()->toIso8601String(),
            ], $payload),
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);

        $this->processor->attempt($delivery);
        return $delivery->fresh();
    }
}
