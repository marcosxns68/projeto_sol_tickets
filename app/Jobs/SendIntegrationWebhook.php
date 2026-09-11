<?php

namespace App\Jobs;

use App\Models\ConnectedSystem;
use App\Services\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SendIntegrationWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 20;

    public function __construct(
        public int $integrationId,
        public string $event,
        public array $payload,
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $integration = ConnectedSystem::find($this->integrationId);
        if (!$integration || !$integration->active || !$integration->webhook_url) {
            return;
        }

        $secret = $integration->webhookSigningSecret();
        if (!$secret) {
            return;
        }

        app(WebhookUrlGuard::class)->assertAllowed($integration->webhook_url);

        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Não foi possível serializar o webhook.');
        }

        $response = Http::timeout(12)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Sutoorii-Event' => $this->event,
                'X-Sutoorii-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ])
            ->withBody($body, 'application/json')
            ->post($integration->webhook_url);

        if (!$response->successful()) {
            throw new RuntimeException('Webhook retornou HTTP '.$response->status().'.');
        }
    }
}
