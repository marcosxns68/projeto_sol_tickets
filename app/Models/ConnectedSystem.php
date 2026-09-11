<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
}
