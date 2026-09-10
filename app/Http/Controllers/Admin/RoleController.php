<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        return view('admin.roles.index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:roles,name'],
            'active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = DB::transaction(function () use ($request, $data) {
            $role = Role::create([
                'name' => trim($data['name']),
                'protected' => false,
                'active' => $request->boolean('active'),
            ]);

            $role->permissions()->sync($data['permissions'] ?? []);
            $this->audit($request, $role, 'role.created', null, $this->snapshot($role));

            return $role;
        });

        return redirect()->route('admin.roles.edit', $role)->with('success', 'Cargo criado. Agora configure as permissões padrão.');
    }

    public function edit(Request $request, Role $role)
    {
        $this->authorizeAccess($request);

        return view('admin.roles.edit', [
            'role' => $role->load('permissions'),
            'permissions' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'selectedPermissions' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')->ignore($role->id)],
            'active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        DB::transaction(function () use ($request, $role, $data) {
            $old = $this->snapshot($role);
            $hadAdministrator = $this->installationHasAdministrator();

            $role->update([
                'name' => trim($data['name']),
                'active' => $request->boolean('active'),
            ]);
            $role->permissions()->sync($data['permissions'] ?? []);

            if ($hadAdministrator && !$this->installationHasAdministrator()) {
                throw ValidationException::withMessages([
                    'role' => 'Essa alteração deixaria o sistema sem nenhum usuário capaz de administrar usuários e permissões.',
                ]);
            }

            $this->audit($request, $role, 'role.updated', $old, $this->snapshot($role));
        });

        return redirect()->route('admin.roles.edit', $role)->with('success', 'Cargo atualizado.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->hasPermission('roles.manage'), 403);
    }

    private function installationHasAdministrator(): bool
    {
        return User::where('active', true)->get()->contains(
            fn (User $user) => $user->hasPermission('users.manage') && $user->hasPermission('permissions.manage')
        );
    }

    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'active' => (bool) $role->active,
            'permissions' => $role->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all(),
        ];
    }

    private function audit(Request $request, Role $role, string $event, ?array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'auditable_type' => Role::class,
            'auditable_id' => $role->id,
            'event' => $event,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
