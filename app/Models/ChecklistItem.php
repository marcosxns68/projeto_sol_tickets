<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    protected $fillable = ['ticket_id', 'text', 'required', 'completed', 'completed_by', 'completed_at', 'position'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'completed' => 'boolean', 'completed_at' => 'datetime'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
