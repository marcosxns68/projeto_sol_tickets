<?php

namespace App\Services;

use App\Models\ConnectedSystem;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IntegrationUserDirectory
{
    private const ENDPOINT = '/api/sutoorii/users.php';

    public function __construct(private WebhookUrlGuard $urlGuard)
    {
    }

    public function search(ConnectedSystem $system, string $query): array
    {
        return $this->request($system, ['search' => trim($query)]);
    }

    public function find(ConnectedSystem $system, string $externalId): ?array
    {
        $users = $this->request($system, ['id' => trim($externalId)]);

        foreach ($users as $user) {
            if ((string) $user['id'] === (string) $externalId) {
                return $user;
            }
        }

        return null;
    }

    private function request(ConnectedSystem $system, array $parameters): array
    {
        if (!$system->active || !$system->base_url) {
            throw new RuntimeException('A integração não possui diretório de usuários disponível.');
        }

        $secret = $system->webhookSigningSecret();
        if (!$secret) {
            throw new RuntimeException('A integração não possui autenticação para o diretório de usuários.');
        }

        $url = rtrim($system->base_url, '/').self::ENDPOINT;
        $this->urlGuard->assertAllowed($url);

        ksort($parameters);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $timestamp = (string) time();
        $canonical = "GET\n".self::ENDPOINT."\n".$query."\n".$timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $canonical, $secret);

        $response = Http::timeout(8)
            ->acceptJson()
            ->withHeaders([
                'X-Sutoorii-Timestamp' => $timestamp,
                'X-Sutoorii-Signature' => $signature,
            ])
            ->get($url, $parameters);

        if (!$response->successful()) {
            throw new RuntimeException('O diretório de usuários da integração está indisponível.');
        }

        $data = $response->json('data');
        if (!is_array($data)) {
            throw new RuntimeException('A integração retornou uma resposta inválida para o diretório de usuários.');
        }

        $users = [];
        foreach (array_slice($data, 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            $email = isset($item['email']) ? trim((string) $item['email']) : null;

            if ($id === '' || $name === '') {
                continue;
            }
            if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $email = null;
            }

            $users[] = [
                'id' => $id,
                'name' => $name,
                'email' => $email ?: null,
            ];
        }

        return $users;
    }
}
