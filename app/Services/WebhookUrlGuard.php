<?php

namespace App\Services;

class WebhookUrlGuard
{
    public function isAllowed(?string $url): bool
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($host === '') {
            return false;
        }

        if (!app()->environment(['local', 'testing']) && $scheme !== 'https') {
            return false;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.local')) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return app()->environment(['local', 'testing']);
    }
}
