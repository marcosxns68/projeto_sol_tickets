<?php

namespace App\Models;

use App\Services\IntegrationSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ConnectedSystem extends Model
{
    protected $table = 'systems';

    protected $fillable = [
        'company_id',
        'name',
        'base_url',
        'webhook_url',
        'active',
    ];

    protected $hidden = [
        'api_token_hash',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function issueApiToken(): string
    {
        $token = 'st_live_'.Str::random(48);

        $this->forceFill([
            'api_token_hash' => hash('sha256', $token),
        ])->save();

        return $token;
    }

    public function issueWebhookSecret(): string
    {
        $secret = 'whsec_'.Str::random(48);

        app(IntegrationSettings::class)->put(
            $this->getKey(),
            'webhook_secret',
            Crypt::encryptString($secret)
        );

        return $secret;
    }

    public function webhookSigningSecret(): ?string
    {
        $encrypted = app(IntegrationSettings::class)->get(
            $this->getKey(),
            'webhook_secret',
            $this->getAttribute('webhook_secret')
        );

        if (!$encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // Compatibilidade defensiva com algum valor legado anterior à criptografia.
            return $encrypted;
        }
    }
}
