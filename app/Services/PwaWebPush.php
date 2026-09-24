<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use RuntimeException;

class PwaWebPush
{
    /**
     * Persist one VAPID pair for the entire installation. Lock a stable
     * setting row to avoid concurrent requests rotating device subscriptions.
     */
    public function keys(): array
    {
        return DB::transaction(function () {
            DB::table('settings')->insertOrIgnore([
                'key' => 'pwa_push.vapid', 'value' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('settings')->where('key', 'pwa_push.vapid')
                ->lockForUpdate()->first();
            $saved = json_decode((string) $row->value, true);
            if (is_array($saved) && !empty($saved['publicKey']) && !empty($saved['encryptedPrivateKey'])) {
                return [
                    'publicKey' => $saved['publicKey'],
                    'privateKey' => Crypt::decryptString($saved['encryptedPrivateKey']),
                ];
            }

            $pair = VAPID::createVapidKeys();
            DB::table('settings')->where('key', 'pwa_push.vapid')
                ->update(['value' => json_encode([
                    'publicKey' => $pair['publicKey'],
                    'encryptedPrivateKey' => Crypt::encryptString($pair['privateKey']),
                ], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            return $pair;
        });
    }

    public function publicKey(): string
    {
        return $this->keys()['publicKey'];
    }

    public function sender(): WebPush
    {
        $keys = $this->keys();

        return new WebPush([
            'VAPID' => [
                'subject' => 'mailto:contato@sutoorii.com',
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
            ],
        ], ['TTL' => 86400, 'urgency' => 'high', 'contentType' => 'application/json']);
    }

    /**
     * A subscription is not an arbitrary URL: reject private/foreign
     * destinations so that this authenticated endpoint cannot become SSRF.
     */
    public static function validEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > 2048 || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || isset($url['port'])) {
            return false;
        }
        $host = strtolower((string) ($url['host'] ?? ''));

        return in_array($host, [
            'fcm.googleapis.com',
            'updates.push.services.mozilla.com',
            'web.push.apple.com',
            'notify.windows.com',
        ], true)
            || str_ends_with($host, '.push.apple.com')
            || str_ends_with($host, '.notify.windows.com');
    }

    public static function validateSubscription(array $value): ?array
    {
        $endpoint = $value['endpoint'] ?? null;
        $keys = $value['keys'] ?? null;
        if (!is_string($endpoint) || !self::validEndpoint($endpoint)
            || !is_array($keys)) {
            return null;
        }

        $p256dh = $keys['p256dh'] ?? null;
        $auth = $keys['auth'] ?? null;
        if (!is_string($p256dh) || !preg_match('/^[A-Za-z0-9_-]{80,120}$/D', $p256dh)
            || !is_string($auth) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $auth)) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    public function send(array $value, array $payload)
    {
        $subscription = Subscription::create($value);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->sender()->sendOneNotification($subscription, $json, [
            'TTL' => 86400,
            'urgency' => 'high',
        ]);
    }
}
