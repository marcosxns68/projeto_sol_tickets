<?php

namespace App\Services;

use RuntimeException;

class WebhookUrlGuard
{
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw new RuntimeException('O webhook precisa usar HTTPS e possuir um host válido.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('Destino de webhook privado não permitido.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (!$ips) {
            throw new RuntimeException('Não foi possível resolver o host do webhook.');
        }

        foreach ($ips as $ip) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($public === false) {
                throw new RuntimeException('Destino de webhook privado ou reservado não permitido.');
            }
        }
    }
}
