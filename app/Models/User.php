<?php

namespace App\Models;

use App\Notifications\ResetPasswordPtBrNotification;
use App\Notifications\VerifyEmailPtBrNotification;
use App\Services\WhatsAppConnection;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'email_verified_at', 'password', 'role_id', 'department_id', 'active', 'whatsapp', 'whatsapp_reply_enabled', 'notifications_per_page'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'whatsapp' => 'encrypted',
            'whatsapp_reply_enabled' => 'boolean',
            'notifications_per_page' => 'integer',
        ];
    }

    public function setWhatsappAttribute(?string $value): void
    {
        $this->attributes['whatsapp'] = $value === null || trim($value) === ''
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString(
                WhatsAppConnection::normalizeNumber($value)
                    ?? throw new \InvalidArgumentException('WhatsApp inválido.')
            );
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailPtBrNotification());
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordPtBrNotification((string) $token));
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'department_user_access')
            ->withPivot(['access_level', 'follow_department', 'notify_email', 'notify_whatsapp', 'notify_push', 'last_seen_at'])
            ->withTimestamps();
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
