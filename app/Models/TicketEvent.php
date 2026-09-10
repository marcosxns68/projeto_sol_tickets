<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketEvent extends Model
{
    protected $fillable = ['ticket_id', 'actor_id', 'event', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
