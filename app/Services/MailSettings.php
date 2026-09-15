<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MailSettings
{
    private const KEYS = [
        'host' => 'mail.host',
        'port' => 'mail.port',
        'username' => 'mail.username',
        'password' => 'mail.password_encrypted',
        'encryption' => 'mail.encryption',
        'from_address' => 'mail.from_address',
        'from_name' => 'mail.from_name',
    ];

    public function values(): array
    {
        $stored = DB::table('settings')
            ->whereIn('key', array_values(self::KEYS))
            ->pluck('value', 'key');

        return [
            'host' => $stored[self::KEYS['host']] ?? config('mail.mailers.smtp.host'),
            'port' => (int) ($stored[self::KEYS['port']] ?? config('mail.mailers.smtp.port', 465)),
            'username' => $stored[self::KEYS['username']] ?? config('mail.mailers.smtp.username'),
            'encryption' => $stored[self::KEYS['encryption']] ?? $this->encryptionFromConfig(),
            'from_address' => $stored[self::KEYS['from_address']] ?? config('mail.from.address'),
            'from_name' => $stored[self::KEYS['from_name']] ?? config('mail.from.name'),
            'password_saved' => isset($stored[self::KEYS['password']]),
        ];
    }

    public function save(array $data): void
    {
        $values = [
            self::KEYS['host'] => (string) $data['host'],
            self::KEYS['port'] => (string) $data['port'],
            self::KEYS['username'] => (string) ($data['username'] ?? ''),
            self::KEYS['encryption'] => (string) $data['encryption'],
            self::KEYS['from_address'] => (string) $data['from_address'],
            self::KEYS['from_name'] => (string) $data['from_name'],
        ];

        if (isset($data['password']) && $data['password'] !== '') {
            $values[self::KEYS['password']] = Crypt::encryptString((string) $data['password']);
        }

        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    [
                        'value' => $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });
    }

    public function hasStoredPassword(): bool
    {
        return DB::table('settings')->where('key', self::KEYS['password'])->exists();
    }

    public function apply(): void
    {
        $stored = DB::table('settings')
            ->whereIn('key', array_values(self::KEYS))
            ->pluck('value', 'key');

        if ($stored->isEmpty()) {
            return;
        }

        $password = config('mail.mailers.smtp.password');
        if (isset($stored[self::KEYS['password']])) {
            try {
                $password = Crypt::decryptString((string) $stored[self::KEYS['password']]);
            } catch (DecryptException) {
                // Mantém o fallback do ambiente sem expor ou registrar a credencial inválida.
            }
        }

        $encryption = (string) ($stored[self::KEYS['encryption']] ?? $this->encryptionFromConfig());

        config([
            'mail.mailers.smtp.host' => $stored[self::KEYS['host']] ?? config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => (int) ($stored[self::KEYS['port']] ?? config('mail.mailers.smtp.port', 465)),
            'mail.mailers.smtp.username' => $stored[self::KEYS['username']] ?? config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.scheme' => $this->schemeFor($encryption),
            'mail.from.address' => $stored[self::KEYS['from_address']] ?? config('mail.from.address'),
            'mail.from.name' => $stored[self::KEYS['from_name']] ?? config('mail.from.name'),
        ]);
    }

    private function encryptionFromConfig(): string
    {
        $scheme = config('mail.mailers.smtp.scheme');

        return $scheme === 'smtps' ? 'ssl' : 'tls';
    }

    private function schemeFor(string $encryption): ?string
    {
        return match ($encryption) {
            'ssl' => 'smtps',
            'tls' => 'smtp',
            default => null,
        };
    }
}
