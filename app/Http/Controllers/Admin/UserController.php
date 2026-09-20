<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WhatsAppConnection;
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
            'managedUser' => $user->load(['role.permissions', 'department', 'permissionOverrides']),
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
            'whatsapp' => ['nullable', 'string', 'max:30', function ($field, $value, $fail) {
                if (filled($value) && WhatsAppConnection::normalizeNumber($value) === null) {
                    $fail('Informe um WhatsApp com DDD válido para este usuário.');
                }
            }],
            'whatsapp_reply_enabled' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['nullable', Rule::in(['yes', 'no'])],
        ]);

        if ($request->has('permissions')) {
            abort_unless($actor->hasPermission('permissions.manage'), 403);
        }

        $data['active'] = $request->boolean('active');
        $normalizedEmail = strtolower($data['email']);
        $emailChanged = $normalizedEmail !== strtolower($user->email);

        if ($user->id === $actor->id && !$this->hasAnotherAdministrator($user)) {
            if (!$data['active'] || !$this->wouldKeepCriticalPermissions($user, $data['role_id'] ?? null, $request->input('permissions'))) {
                return back()->withErrors(['user' => 'Não é possível remover o último acesso capaz de administrar usuários e permissões.'])->withInput();
            }
        }

        $old = $user->only(['name', 'email', 'email_verified_at', 'role_id', 'department_id', 'active']);

        DB::transaction(function () use ($request, $user, $data, $actor, $old, $normalizedEmail, $emailChanged) {
            $user->update([
                'name' => $data['name'],
                'email' => $normalizedEmail,
                'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
                'role_id' => $data['role_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'active' => $data['active'],
                'whatsapp' => array_key_exists('whatsapp', $data) ? $data['whatsapp'] : $user->whatsapp,
                'whatsapp_reply_enabled' => $request->has('whatsapp_reply_enabled')
                    ? $request->boolean('whatsapp_reply_enabled')
                    : $user->whatsapp_reply_enabled,
            ]);

            if ($request->has('permissions')) {
                $rolePermissionIds = ($data['role_id'] ?? null)
                    ? Role::find($data['role_id'])?->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all() ?? []
                    : [];

                $user->permissionOverrides()->delete();

                foreach ((array) $request->input('permissions', []) as $permissionId => $answer) {
                    $permission = Permission::find($permissionId);
                    if (!$permission) {
                        continue;
                    }

                    $wantsPermission = $answer === 'yes';
                    $roleGrantsPermission = in_array((int) $permissionId, $rolePermissionIds, true);

                    if ($wantsPermission === $roleGrantsPermission) {
                        continue;
                    }

                    $user->permissionOverrides()->create([
                        'permission_id' => (int) $permissionId,
                        'effect' => $wantsPermission ? 'allow' : 'deny',
                    ]);
                }
            }

            DB::table('audit_logs')->insert([
                'user_id' => $actor->id,
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'event' => 'user.updated',
                'old_values' => json_encode($old, JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode($user->fresh()->only(['name', 'email', 'email_verified_at', 'role_id', 'department_id', 'active']), JSON_UNESCAPED_UNICODE),
                'ip_address' => request()->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->route('admin.users.edit', $user)->with('success', 'Usuário atualizado.');
    }

    public function resendVerification(Request $request, User $user)
    {
        $actor = $request->user();
        $this->authorizeVerificationManagement($actor);

        if ($user->hasVerifiedEmail()) {
            return redirect()
                ->route('admin.users.edit', $user)
                ->withErrors(['verification' => 'Esta conta já teve o e-mail confirmado. Use a opção para redefinir a confirmação se necessário.']);
        }

        $user->sendEmailVerificationNotification();

        DB::table('audit_logs')->insert([
            'user_id' => $actor->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => 'user.verification_resent',
            'old_values' => null,
            'new_values' => json_encode([
                'email' => $user->email,
                'resent_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('success', 'E-mail de confirmação reenviado para '.$user->email.'.');
    }

    public function resetVerificationAndResend(Request $request, User $user)
    {
        $actor = $request->user();
        $this->authorizeVerificationManagement($actor);

        $previousVerifiedAt = $user->email_verified_at;
        $user->forceFill(['email_verified_at' => null])->save();
        $user->sendEmailVerificationNotification();

        DB::table('audit_logs')->insert([
            'user_id' => $actor->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => 'user.verification_reset_and_resent',
            'old_values' => json_encode([
                'email' => $user->email,
                'email_verified_at' => $previousVerifiedAt?->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode([
                'email' => $user->email,
                'email_verified_at' => null,
                'resent_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('success', 'Confirmação redefinida e novo e-mail enviado para '.$user->email.'.');
    }

    private function authorizeVerificationManagement(User $actor): void
    {
        abort_unless(
            $actor->hasPermission('users.manage') && $actor->hasPermission('permissions.manage'),
            403
        );
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

        foreach (['users.manage', 'permissions.manage'] as $key) {
            $permission = Permission::where('key', $key)->first();
            if (!$permission) {
                return false;
            }

            if ($submittedOverrides !== null && array_key_exists($permission->id, $submittedOverrides)) {
                if ($submittedOverrides[$permission->id] === 'yes') {
                    continue;
                }

                if ($submittedOverrides[$permission->id] === 'no') {
                    return false;
                }
            }

            $effect = $existing[$permission->id] ?? 'inherit';
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
