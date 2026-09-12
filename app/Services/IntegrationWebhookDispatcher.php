<?php

namespace App\Services;

use App\Jobs\SendIntegrationWebhook;
use App\Models\Ticket;

class IntegrationWebhookDispatcher
{
    public function dispatch(Ticket $ticket, string $event, array $details = []): void
    {
        if (!$ticket->system_id) {
            return;
        }

        $ticket->loadMissing(['system', 'status']);
        $integration = $ticket->system;

        if (!$integration || !$integration->active || !$integration->webhook_url || !$integration->webhookSigningSecret()) {
            return;
        }

        $payload = array_merge([
            'event' => $event,
            'ticket_number' => $ticket->number,
            'external_reference' => $ticket->external_reference,
            'status' => $ticket->status?->name,
            'status_key' => $ticket->status?->system_key,
            'occurred_at' => now()->toIso8601String(),
        ], $details);

        SendIntegrationWebhook::dispatch($integration->id, $event, $payload);
    }
}
