<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'email_verified_at', 'password', 'role_id', 'department_id', 'active'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function permissionOverrides()
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function hasPermission(string $key): bool
    {
        if (!$this->active) {
            return false;
        }

        $permission = Permission::where('key', $key)->first();
        if (!$permission) {
            return false;
        }

        $effect = $this->permissionOverrides()
            ->where('permission_id', $permission->id)
            ->value('effect');

        if ($effect === 'allow') {
            return true;
        }

        if ($effect === 'deny') {
            return false;
        }

        return $this->role?->permissions()->whereKey($permission->id)->exists() ?? false;
    }

    public function effectivePermissionKeys(): array
    {
        return Permission::query()
            ->get()
            ->filter(fn (Permission $permission) => $this->hasPermission($permission->key))
            ->pluck('key')
            ->all();
    }
}
