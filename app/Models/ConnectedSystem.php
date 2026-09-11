<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ConnectedSystem extends Model
{
    protected $table = 'systems';

    protected $fillable = [
        'company_id', 'department_id', 'name', 'base_url', 'api_token_hash', 'webhook_url',
        'webhook_secret', 'webhook_secret_encrypted', 'active', 'last_api_activity_at',
        'last_webhook_attempt_at', 'last_webhook_success_at', 'last_webhook_status',
    ];

    protected $hidden = ['api_token_hash', 'webhook_secret', 'webhook_secret_encrypted'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_api_activity_at' => 'datetime',
            'last_webhook_attempt_at' => 'datetime',
            'last_webhook_success_at' => 'datetime',
        ];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function tickets() { return $this->hasMany(Ticket::class, 'system_id'); }
    public function webhookDeliveries() { return $this->hasMany(WebhookDelivery::class, 'system_id'); }

    public function issueApiToken(): string
    {
        $plain = 'st_live_'.Str::random(48);
        $this->forceFill(['api_token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }

    public function matchesToken(string $plain): bool
    {
        return $this->api_token_hash !== null
            && hash_equals($this->api_token_hash, hash('sha256', $plain));
    }

    public function issueWebhookSecret(): string
    {
        $plain = 'whsec_'.Str::random(48);
        $this->forceFill([
            'webhook_secret' => null,
            'webhook_secret_encrypted' => Crypt::encryptString($plain),
        ])->save();

        return $plain;
    }

    public function webhookSecretPlain(): ?string
    {
        if ($this->webhook_secret_encrypted) {
            return Crypt::decryptString($this->webhook_secret_encrypted);
        }

        return $this->webhook_secret;
    }
}
