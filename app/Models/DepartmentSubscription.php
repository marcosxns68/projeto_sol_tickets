<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentSubscription extends Model
{
    protected $fillable = [
        'user_id', 'department_id', 'notify_email', 'notify_whatsapp', 'notify_push', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'notify_email' => 'boolean',
            'notify_whatsapp' => 'boolean',
            'notify_push' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function isFollowing(): bool
    {
        return $this->notify_email || $this->notify_whatsapp || $this->notify_push;
    }
}
