<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['name', 'active'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'department_user_access')
            ->withPivot(['access_level', 'follow_department', 'notify_email', 'notify_whatsapp', 'notify_push', 'last_seen_at'])
            ->withTimestamps();
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
