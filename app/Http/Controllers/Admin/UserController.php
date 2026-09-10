<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);

        return view('admin.users.index', [
            'users' => User::with(['role', 'department'])->orderBy('name')->paginate(30),
        ]);
    }

    public function edit(Request $request, User $user)
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);

        return view('admin.users.edit', [
            'managedUser' => $user->load(['role', 'department', 'permissionOverrides']),
            'roles' => Role::where('active', true)->orderBy('name')->get(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'permissions' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'overrides' => $user->permissionOverrides()->pluck('effect', 'permission_id')->all(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('users.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['nullable', Rule::in(['inherit', 'allow', 'deny'])],
        ]);

        if ($request->has('permissions')) {
            abort_unless($actor->hasPermission('permissions.manage'), 403);
        }

        $data['active'] = $request->boolean('active');

        if ($user->id === $actor->id && !$this->hasAnotherAdministrator($user)) {
            if (!$data['active'] || !$this->wouldKeepCriticalPermissions($user, $data['role_id'] ?? null, $request->input('permissions'))) {
                return back()->withErrors(['user' => 'Não é possível remover o último acesso capaz de administrar usuários e permissões.'])->withInput();
            }
        }

        $old = $user->only(['name', 'email', 'role_id', 'department_id', 'active']);

        DB::transaction(function () use ($request, $user, $data, $actor, $old) {
            $user->update([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'role_id' => $data['role_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'active' => $data['active'],
            ]);

            if ($request->has('permissions')) {
                $user->permissionOverrides()->delete();
                foreach ((array) $request->input('permissions', []) as $permissionId => $effect) {
                    if (in_array($effect, ['allow', 'deny'], true) && Permission::whereKey($permissionId)->exists()) {
                        $user->permissionOverrides()->create([
                            'permission_id' => (int) $permissionId,
                            'effect' => $effect,
                        ]);
                    }
                }
            }

            DB::table('audit_logs')->insert([
                'user_id' => $actor->id,
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'event' => 'user.updated',
                'old_values' => json_encode($old, JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode($user->fresh()->only(['name', 'email', 'role_id', 'department_id', 'active']), JSON_UNESCAPED_UNICODE),
                'ip_address' => request()->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->route('admin.users.edit', $user)->with('success', 'Usuário atualizado.');
    }

    private function hasAnotherAdministrator(User $user): bool
    {
        return User::where('active', true)->whereKeyNot($user->id)->get()->contains(
            fn (User $candidate) => $candidate->hasPermission('users.manage') && $candidate->hasPermission('permissions.manage')
        );
    }

    private function wouldKeepCriticalPermissions(User $user, ?int $roleId, ?array $submittedOverrides): bool
    {
        $role = $roleId ? Role::with('permissions')->find($roleId) : null;
        $existing = $user->permissionOverrides()->pluck('effect', 'permission_id')->all();
        $overrides = $submittedOverrides === null ? $existing : $submittedOverrides;

        foreach (['users.manage', 'permissions.manage'] as $key) {
            $permission = Permission::where('key', $key)->first();
            if (!$permission) {
                return false;
            }

            $effect = $overrides[$permission->id] ?? $overrides[(string) $permission->id] ?? 'inherit';
            if ($effect === 'deny') {
                return false;
            }
            if ($effect === 'allow') {
                continue;
            }
            if (!$role?->permissions->contains('id', $permission->id)) {
                return false;
            }
        }

        return true;
    }
}
