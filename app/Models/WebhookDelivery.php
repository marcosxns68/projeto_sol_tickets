<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'system_id', 'ticket_id', 'delivery_uuid', 'event', 'payload', 'attempts', 'status',
        'last_http_status', 'last_error', 'next_attempt_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function integration() { return $this->belongsTo(ConnectedSystem::class, 'system_id'); }
    public function ticket() { return $this->belongsTo(Ticket::class); }
}
