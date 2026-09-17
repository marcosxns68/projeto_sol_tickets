<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recurrence extends Model
{
    protected $fillable = [
        'source_ticket_id', 'frequency', 'interval', 'weekdays', 'next_run_at', 'ends_at', 'active',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'next_run_at' => 'datetime',
            'ends_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function sourceTicket()
    {
        return $this->belongsTo(Ticket::class, 'source_ticket_id');
    }
}
