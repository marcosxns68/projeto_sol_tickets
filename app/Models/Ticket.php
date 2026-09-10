<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'number', 'origin', 'title', 'description', 'priority', 'status_id', 'creator_id',
        'assignee_id', 'department_id', 'company_id', 'system_id', 'requester_name',
        'requester_email', 'external_requester_id', 'due_at', 'completed_at', 'trashed_at',
        'external_reference',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'trashed_at' => 'datetime'];
    }

    public function status() { return $this->belongsTo(Status::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id'); }
    public function creator() { return $this->belongsTo(User::class, 'creator_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function system() { return $this->belongsTo(ConnectedSystem::class, 'system_id'); }
    public function participants() { return $this->belongsToMany(User::class, 'ticket_participants')->withPivot(['type', 'notify_status', 'notify_comments', 'notify_attachments'])->withTimestamps(); }
    public function labels() { return $this->belongsToMany(Label::class); }
    public function checklist() { return $this->hasMany(ChecklistItem::class)->orderBy('position'); }
    public function comments() { return $this->hasMany(Comment::class); }
    public function events() { return $this->hasMany(TicketEvent::class)->orderBy('created_at')->orderBy('id'); }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('tickets.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('assignee_id', $user->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->where('users.id', $user->id));

            if ($user->department_id && $user->hasPermission('tickets.view_department')) {
                $q->orWhere('department_id', $user->department_id);
            }
        });
    }

    public function scopeMyBox(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('assignee_id', $user->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->where('users.id', $user->id));
        });
    }

    public function scopeActiveForBox(Builder $query): Builder
    {
        return $query
            ->whereNull('trashed_at')
            ->whereHas('status', fn (Builder $status) => $status->whereNotIn('category', ['completed', 'cancelled']));
    }

    public static function nextNumber(): string
    {
        do {
            $number = now()->format('ym').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (static::where('number', $number)->exists());

        return $number;
    }
}
