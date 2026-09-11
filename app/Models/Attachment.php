<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = [
        'ticket_id', 'comment_id', 'uploaded_by', 'disk', 'path', 'original_name',
        'mime_type', 'size', 'expires_at', 'deleted_at',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function ticket() { return $this->belongsTo(Ticket::class); }
    public function comment() { return $this->belongsTo(Comment::class); }
}
