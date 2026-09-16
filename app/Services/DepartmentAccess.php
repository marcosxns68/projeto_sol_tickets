<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DepartmentAccess
{
    private const RANK = ['send' => 1, 'view' => 2, 'edit' => 3];

    public function level(User $user, Department|int $department): ?string
    {
        $departmentId = $department instanceof Department ? $department->id : (int) $department;

        if ($user->hasPermission('tickets.view_all')) {
            return 'edit';
        }

        $level = DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->where('department_id', $departmentId)
            ->value('access_level');

        if (is_string($level) && isset(self::RANK[$level])) {
            return $level;
        }

        if ((int) $user->department_id === $departmentId) {
            return 'view';
        }

        return null;
    }

    public function canSend(User $user, Department|int $department): bool
    {
        if ($user->hasPermission('tickets.view_all') || $user->hasPermission('departments.manage')) {
            return true;
        }

        return $this->rank($this->level($user, $department)) >= self::RANK['send'];
    }

    public function canView(User $user, Department|int $department): bool
    {
        return $this->rank($this->level($user, $department)) >= self::RANK['view'];
    }

    public function canEdit(User $user, Department|int $department): bool
    {
        return $this->rank($this->level($user, $department)) >= self::RANK['edit'];
    }

    public function sendableIds(User $user): array
    {
        if ($user->hasPermission('tickets.view_all') || $user->hasPermission('departments.manage')) {
            return Department::query()->where('active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->idsForLevels($user, ['send', 'view', 'edit']);
    }

    public function viewableIds(User $user): array
    {
        if ($user->hasPermission('tickets.view_all')) {
            return Department::query()->where('active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->idsForLevels($user, ['view', 'edit']);
    }

    public function followedDepartmentIds(User $user): array
    {
        return DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->whereIn('access_level', ['view', 'edit'])
            ->where('follow_department', true)
            ->pluck('department_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function idsForLevels(User $user, array $levels): array
    {
        $rows = DB::table('department_user_access')
            ->where('user_id', $user->id)
            ->whereIn('access_level', $levels)
            ->pluck('department_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $legacy = (int) $user->department_id;
        if ($legacy > 0) {
            $hasExplicit = DB::table('department_user_access')
                ->where('user_id', $user->id)
                ->where('department_id', $legacy)
                ->exists();

            if (!$hasExplicit && in_array('view', $levels, true)) {
                $rows[] = $legacy;
            }
        }

        return array_values(array_unique($rows));
    }

    private function rank(?string $level): int
    {
        return $level !== null ? (self::RANK[$level] ?? 0) : 0;
    }
}
