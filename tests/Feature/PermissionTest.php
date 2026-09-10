<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRolePermission(bool $roleAllows): array
    {
        $permission = Permission::create([
            'key' => 'tickets.reassign',
            'name' => 'Reatribuir responsável',
            'group' => 'tickets',
        ]);

        $role = Role::create(['name' => 'Teste']);
        if ($roleAllows) {
            $role->permissions()->attach($permission->id);
        }

        $user = User::create([
            'name' => 'Usuário teste',
            'email' => uniqid('teste').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);

        return [$user, $permission];
    }

    public function test_explicit_deny_overrides_role_allow(): void
    {
        [$user, $permission] = $this->userWithRolePermission(true);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($user->hasPermission('tickets.reassign'));
    }

    public function test_explicit_allow_overrides_role_deny(): void
    {
        [$user, $permission] = $this->userWithRolePermission(false);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($user->hasPermission('tickets.reassign'));
    }

    public function test_without_override_permission_is_inherited_from_role(): void
    {
        [$user] = $this->userWithRolePermission(true);
        $this->assertTrue($user->hasPermission('tickets.reassign'));
    }
}
