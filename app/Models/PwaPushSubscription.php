<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PwaPushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint_hash', 'subscription', 'device_label', 'last_success_at',
    ];

    protected function casts(): array
    {
        return ['subscription' => 'encrypted:array', 'last_success_at' => 'datetime'];
    }
}
