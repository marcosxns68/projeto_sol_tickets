<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDepartmentRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_manager_can_create_and_update_departments(): void
    {
        $permission = Permission::where('key', 'departments.manage')->firstOrFail();
        $role = Role::create(['name' => 'Gestor de departamentos']);
        $role->permissions()->attach($permission->id);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-dept@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($admin)->get('/admin/departamentos')->assertOk()->assertSee('Departamentos');

        $this->actingAs($admin)->post('/admin/departamentos', [
            'name' => 'Desenvolvimento',
            'active' => 1,
        ])->assertRedirect('/admin/departamentos');

        $department = Department::where('name', 'Desenvolvimento')->firstOrFail();
        $this->actingAs($admin)->patch('/admin/departamentos/'.$department->id, [
            'name' => 'Desenvolvimento e Produto',
            'active' => 0,
        ])->assertRedirect('/admin/departamentos');

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'name' => 'Desenvolvimento e Produto',
            'active' => 0,
        ]);
    }

    public function test_user_without_department_permission_cannot_manage_departments(): void
    {
        $role = Role::create(['name' => 'Sem departamentos']);
        $user = User::create([
            'name' => 'Normal',
            'email' => 'normal-dept@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user)->get('/admin/departamentos')->assertForbidden();
    }

    public function test_role_manager_can_create_and_update_role_default_permissions(): void
    {
        $manageRoles = Permission::where('key', 'roles.manage')->firstOrFail();
        $ticketCreate = Permission::where('key', 'tickets.create')->firstOrFail();
        $ticketComment = Permission::where('key', 'tickets.comment')->firstOrFail();

        $adminRole = Role::create(['name' => 'Gestor de cargos']);
        $adminRole->permissions()->attach($manageRoles->id);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-role@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $adminRole->id,
            'active' => true,
        ]);

        $this->actingAs($admin)->get('/admin/cargos')->assertOk()->assertSee('Cargos');

        $this->actingAs($admin)->post('/admin/cargos', [
            'name' => 'Atendimento',
            'active' => 1,
            'permissions' => [$ticketCreate->id, $ticketComment->id],
        ])->assertRedirect();

        $role = Role::where('name', 'Atendimento')->firstOrFail();
        $this->assertTrue($role->permissions()->whereKey($ticketCreate->id)->exists());
        $this->assertTrue($role->permissions()->whereKey($ticketComment->id)->exists());

        $this->actingAs($admin)->patch('/admin/cargos/'.$role->id, [
            'name' => 'Atendimento N1',
            'active' => 1,
            'permissions' => [$ticketComment->id],
        ])->assertRedirect();

        $role->refresh();
        $this->assertSame('Atendimento N1', $role->name);
        $this->assertFalse($role->permissions()->whereKey($ticketCreate->id)->exists());
        $this->assertTrue($role->permissions()->whereKey($ticketComment->id)->exists());
    }

    public function test_user_without_role_permission_cannot_manage_roles(): void
    {
        $role = Role::create(['name' => 'Sem cargos']);
        $user = User::create([
            'name' => 'Normal',
            'email' => 'normal-role@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($user)->get('/admin/cargos')->assertForbidden();
    }
}
