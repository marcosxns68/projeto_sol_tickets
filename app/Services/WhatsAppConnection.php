<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class WhatsAppConnection
{
    public function values(): array
    {
        return [
            'base_url' => (string) Setting::getValue('whatsapp.evolution.base_url', ''),
            'instance' => (string) Setting::getValue('whatsapp.evolution.instance', ''),
            'api_key_saved' => filled(Setting::getValue('whatsapp.evolution.api_key', '')),
        ];
    }

    public function save(array $data): void
    {
        $url = rtrim((string) $data['base_url'], '/');
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || !str_contains($host, '.') ||
            !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
            filter_var($host, FILTER_VALIDATE_IP) ||
            strtolower($host) === 'localhost' ||
            parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            parse_url($url, PHP_URL_USER) !== null ||
            parse_url($url, PHP_URL_PASS) !== null ||
            parse_url($url, PHP_URL_QUERY) !== null ||
            parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException('Informe a URL HTTPS pública da Evolution API.');
        }

        Setting::setValue('whatsapp.evolution.base_url', $url);
        Setting::setValue('whatsapp.evolution.instance', (string) $data['instance']);

        if (filled($data['api_key'] ?? null)) {
            Setting::setValue('whatsapp.evolution.api_key', Crypt::encryptString((string) $data['api_key']));
        }
    }

    public function qrCode(): string
    {
        $response = $this->request('connect');
        $qr = data_get($response, 'base64')
            ?? data_get($response, 'qrcode.base64')
            ?? data_get($response, 'data.base64')
            ?? data_get($response, 'data.code');

        if (!is_string($qr)) {
            throw new RuntimeException('A Evolution API não retornou uma imagem de QR Code. Confira o status da instância.');
        }

        if (!str_starts_with($qr, 'data:image/')) {
            $qr = 'data:image/png;base64,'.$qr;
        }

        if (!preg_match('~^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~', $qr) ||
            strlen($qr) > 600000) {
            throw new RuntimeException('O serviço não retornou uma imagem de QR Code válida.');
        }

        return $qr;
    }

    public function connectionState(): string
    {
        $result = $this->request('connectionState');
        $state = data_get($result, 'instance.state') ?? data_get($result, 'state');
        return is_string($state) && $state !== '' ? $state : 'indisponível';
    }

    private function request(string $action): array
    {
        $data = $this->values();
        if ($data['base_url'] === '' || $data['instance'] === '' || !$data['api_key_saved']) {
            throw new RuntimeException('Salve a URL, a instância e a chave de API antes de conectar.');
        }

        $key = Crypt::decryptString((string) Setting::getValue('whatsapp.evolution.api_key'));

        try {
            $response = Http::withHeaders(['apikey' => $key])
                ->acceptJson()->timeout(12)->connectTimeout(5)->withoutRedirecting()
                ->get($data['base_url'].'/instance/'.$action.'/'.rawurlencode($data['instance']));
        } catch (ConnectionException) {
            throw new RuntimeException('Não foi possível acessar a Evolution API. Confira a URL e a disponibilidade do serviço.');
        }

        if (!$response->successful() || !is_array($response->json())) {
            throw new RuntimeException('A Evolution API não concluiu a solicitação. Confira a instância e a chave de API.');
        }

        return $response->json();
    }
}
