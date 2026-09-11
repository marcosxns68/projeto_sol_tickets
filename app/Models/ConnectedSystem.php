<?php

namespace App\Models;

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

        $this->forceFill([
            'webhook_secret' => Crypt::encryptString($secret),
        ])->save();

        return $secret;
    }

    public function webhookSigningSecret(): ?string
    {
        if (!$this->webhook_secret) {
            return null;
        }

        try {
            return Crypt::decryptString($this->webhook_secret);
        } catch (DecryptException) {
            // Compatibilidade defensiva com algum valor legado anterior à criptografia.
            return $this->webhook_secret;
        }
    }
}
