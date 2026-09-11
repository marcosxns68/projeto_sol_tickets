<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebhookDeliveryProcessor
{
    public function __construct(private WebhookUrlGuard $guard) {}

    public function processPending(int $limit = 50): int
    {
        $deliveries = WebhookDelivery::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', 5)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($deliveries as $delivery) {
            $this->attempt($delivery);
        }

        return $deliveries->count();
    }

    public function attempt(WebhookDelivery $delivery): bool
    {
        $integration = $delivery->integration;
        if (!$integration || !$integration->active || !$this->guard->isAllowed($integration->webhook_url)) {
            $this->fail($delivery, null, 'Webhook ausente, inativo ou destino não permitido.');
            return false;
        }

        $secret = $integration->webhookSecretPlain();
        if (!$secret) {
            $this->fail($delivery, null, 'Segredo do webhook não configurado.');
            return false;
        }

        $raw = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->withHeaders([
                    'X-Sutoorii-Event' => $delivery->event,
                    'X-Sutoorii-Delivery' => $delivery->delivery_uuid,
                    'X-Sutoorii-Timestamp' => $timestamp,
                    'X-Sutoorii-Signature' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->withBody($raw, 'application/json')
                ->post($integration->webhook_url);

            $integration->forceFill(['last_webhook_attempt_at' => now()])->saveQuietly();

            if ($response->successful()) {
                $delivery->update([
                    'attempts' => $delivery->attempts + 1,
                    'status' => 'delivered',
                    'last_http_status' => $response->status(),
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => now(),
                ]);
                $integration->forceFill([
                    'last_webhook_success_at' => now(),
                    'last_webhook_status' => 'success',
                ])->saveQuietly();
                return true;
            }

            $this->fail($delivery, $response->status(), 'HTTP '.$response->status());
        } catch (Throwable $e) {
            $this->fail($delivery, null, $e->getMessage());
        }

        return false;
    }

    private function fail(WebhookDelivery $delivery, ?int $httpStatus, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $delays = [1 => 1, 2 => 5, 3 => 15, 4 => 60];
        $dead = $attempts >= 5;

        $delivery->update([
            'attempts' => $attempts,
            'status' => $dead ? 'dead' : 'failed',
            'last_http_status' => $httpStatus,
            'last_error' => mb_substr($error, 0, 2000),
            'next_attempt_at' => $dead ? null : now()->addMinutes($delays[$attempts] ?? 240),
        ]);

        if ($delivery->integration) {
            $delivery->integration->forceFill([
                'last_webhook_attempt_at' => now(),
                'last_webhook_status' => $dead ? 'dead' : 'failed',
            ])->saveQuietly();
        }
    }
}
