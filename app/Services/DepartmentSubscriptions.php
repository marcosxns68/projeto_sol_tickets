<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DepartmentSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepartmentSubscriptions
{
    public function get(User $user, Department|int $department): ?DepartmentSubscription
    {
        $departmentId = $this->departmentId($department);

        return DepartmentSubscription::query()
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->first();
    }

    public function save(
        User $user,
        Department|int $department,
        bool $email,
        bool $whatsapp,
        bool $push,
    ): ?DepartmentSubscription {
        $departmentId = $this->departmentId($department);
        $following = $email || $whatsapp || $push;
        $existing = $this->get($user, $departmentId);

        if (!$following) {
            $this->remove($user, $departmentId, true);
            return null;
        }

        $subscription = DepartmentSubscription::query()->updateOrCreate(
            ['user_id' => $user->id, 'department_id' => $departmentId],
            [
                'notify_email' => $email,
                'notify_whatsapp' => $whatsapp,
                'notify_push' => $push,
                'last_seen_at' => $existing?->last_seen_at ?? now(),
            ]
        );

        $this->syncLegacyPivot($user->id, $departmentId, true, $email, $whatsapp, $push, $subscription->last_seen_at);

        return $subscription;
    }

    public function remove(User $user, Department|int $department, bool $syncLegacy = false): void
    {
        $departmentId = $this->departmentId($department);
        DepartmentSubscription::query()
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->delete();

        if ($syncLegacy) {
            $this->syncLegacyPivot($user->id, $departmentId, false, false, false, false, null);
        }
    }

    public function isFollowing(User $user, Department|int $department): bool
    {
        $departmentId = $this->departmentId($department);
        $subscription = $this->get($user, $departmentId);
        if ($subscription?->isFollowing()) {
            return true;
        }

        return DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->whereIn('access_level', ['view', 'edit'])
            ->where('follow_department', true)
            ->exists();
    }

    public function lastSeenAt(User $user, Department|int $department)
    {
        $departmentId = $this->departmentId($department);
        $subscription = $this->get($user, $departmentId);
        if ($subscription?->last_seen_at) {
            return $subscription->last_seen_at;
        }

        return DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->where('follow_department', true)
            ->value('last_seen_at');
    }

    public function markSeen(User $user, Department|int $department): bool
    {
        $departmentId = $this->departmentId($department);
        if (!$this->isFollowing($user, $departmentId)) {
            return false;
        }

        $when = now();
        $subscription = $this->get($user, $departmentId);
        if ($subscription) {
            $subscription->update(['last_seen_at' => $when]);
        } else {
            $legacy = DB::table('department_user_access')
                ->where('user_id', $user->id)
                ->where('department_id', $departmentId)
                ->first();
            DepartmentSubscription::query()->create([
                'user_id' => $user->id,
                'department_id' => $departmentId,
                'notify_email' => (bool) ($legacy?->notify_email ?? false),
                'notify_whatsapp' => (bool) ($legacy?->notify_whatsapp ?? false),
                'notify_push' => (bool) ($legacy?->notify_push ?? false),
                'last_seen_at' => $when,
            ]);
        }

        DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->where('follow_department', true)
            ->update(['last_seen_at' => $when, 'updated_at' => $when]);

        return true;
    }

    public function followedDepartmentIds(User $user): array
    {
        $subscriptionIds = DepartmentSubscription::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->where('notify_email', true)
                    ->orWhere('notify_whatsapp', true)
                    ->orWhere('notify_push', true);
            })
            ->pluck('department_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $legacyIds = DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->whereIn('access_level', ['view', 'edit'])
            ->where('follow_department', true)
            ->pluck('department_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($subscriptionIds, $legacyIds)));
    }

    public function recipients(Department|int $department): Collection
    {
        $departmentId = $this->departmentId($department);
        $rows = DB::table('department_subscriptions as ds')
            ->join('users', 'users.id', '=', 'ds.user_id')
            ->leftJoin('department_user_access as dua', function ($join) {
                $join->on('dua.user_id', '=', 'ds.user_id')
                    ->on('dua.department_id', '=', 'ds.department_id');
            })
            ->where('users.active', true)
            ->where('ds.department_id', $departmentId)
            ->select([
                'users.id', 'users.email', 'ds.notify_email', 'ds.notify_whatsapp', 'ds.notify_push',
                'dua.follow_department as legacy_follow', 'dua.access_level as legacy_access',
            ])
            ->get()
            ->filter(function ($row) {
                $selected = (bool) $row->notify_email || (bool) $row->notify_whatsapp || (bool) $row->notify_push;
                $legacy = (bool) $row->legacy_follow && in_array($row->legacy_access, ['view', 'edit'], true);
                $row->legacy_selection = !$selected && $legacy;
                return $selected || $legacy;
            })
            ->values();

        $known = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $legacy = DB::table('department_user_access as dua')
            ->join('users', 'users.id', '=', 'dua.user_id')
            ->where('users.active', true)
            ->where('dua.department_id', $departmentId)
            ->whereIn('dua.access_level', ['view', 'edit'])
            ->where('dua.follow_department', true)
            ->when($known !== [], fn ($query) => $query->whereNotIn('users.id', $known))
            ->select([
                'users.id', 'users.email', 'dua.notify_email', 'dua.notify_whatsapp', 'dua.notify_push',
            ])
            ->get()
            ->map(function ($row) {
                $row->legacy_selection = !(bool) $row->notify_email
                    && !(bool) $row->notify_whatsapp && !(bool) $row->notify_push;
                return $row;
            });

        return $rows->concat($legacy)->values();
    }

    public function channelEnabled(User|int $user, Department|int $department, string $channel): bool
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $departmentId = $this->departmentId($department);
        $column = match ($channel) {
            'email' => 'notify_email',
            'whatsapp' => 'notify_whatsapp',
            'push' => 'notify_push',
            default => throw new \InvalidArgumentException('Canal de departamento inválido.'),
        };

        $subscription = DepartmentSubscription::query()
            ->where('user_id', $userId)
            ->where('department_id', $departmentId)
            ->first();
        if ($subscription && (bool) $subscription->{$column}) {
            return true;
        }

        return (bool) DB::table('department_user_access')
            ->where('user_id', $userId)
            ->where('department_id', $departmentId)
            ->whereIn('access_level', ['view', 'edit'])
            ->where('follow_department', true)
            ->value($column);
    }

    private function syncLegacyPivot(
        int $userId,
        int $departmentId,
        bool $following,
        bool $email,
        bool $whatsapp,
        bool $push,
        $lastSeenAt,
    ): void {
        $query = DB::table('department_user_access')
            ->where('user_id', $userId)
            ->where('department_id', $departmentId);
        if (!$query->exists()) {
            return;
        }

        $query->update([
            'follow_department' => $following,
            'notify_email' => $email,
            'notify_whatsapp' => $whatsapp,
            'notify_push' => $push,
            'last_seen_at' => $following ? $lastSeenAt : null,
            'updated_at' => now(),
        ]);
    }

    private function departmentId(Department|int $department): int
    {
        return $department instanceof Department ? (int) $department->id : (int) $department;
    }
}
