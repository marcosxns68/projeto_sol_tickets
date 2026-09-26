<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['name', 'system_key', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }


    public static function triage(): self
    {
        return static::query()->where('system_key', 'triage')->firstOrFail();
    }

    public function isTriage(): bool
    {
        return $this->system_key === 'triage';
    }

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
