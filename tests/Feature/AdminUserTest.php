<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_manager_can_update_user_and_permission_overrides(): void
    {
        $manageUsers = Permission::firstOrCreate(['key'=>'users.manage'], ['name'=>'Gerenciar usuários','group'=>'users']);
        $managePermissions = Permission::firstOrCreate(['key'=>'permissions.manage'], ['name'=>'Gerenciar permissões','group'=>'permissions']);
        $reassign = Permission::firstOrCreate(['key'=>'tickets.reassign'], ['name'=>'Reatribuir','group'=>'tickets']);

        $adminRole = Role::create(['name'=>'Admin teste']);
        $adminRole->permissions()->attach([$manageUsers->id,$managePermissions->id]);
        $userRole = Role::create(['name'=>'Usuário teste']);
        $department = Department::create(['name'=>'Suporte']);
        $admin = User::create(['name'=>'Admin','email'=>'admin@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$adminRole->id,'department_id'=>$department->id,'active'=>true]);
        $target = User::create(['name'=>'Alvo','email'=>'alvo@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$userRole->id,'department_id'=>$department->id,'active'=>true]);

        $this->actingAs($admin)->get('/admin/usuarios')->assertOk()->assertSee('Alvo');
        $this->actingAs($admin)->patch('/admin/usuarios/'.$target->id, [
            'name'=>'Alvo Atualizado','email'=>'alvo@sutoorii.com','role_id'=>$userRole->id,'department_id'=>$department->id,'active'=>1,
            'permissions'=>[$reassign->id=>'allow'],
        ])->assertRedirect();

        $this->assertSame('Alvo Atualizado',$target->fresh()->name);
        $this->assertDatabaseHas('user_permission_overrides',['user_id'=>$target->id,'permission_id'=>$reassign->id,'effect'=>'allow']);
        $this->assertTrue($target->fresh()->hasPermission('tickets.reassign'));
    }

    public function test_user_without_permission_cannot_access_admin_users(): void
    {
        $role = Role::create(['name'=>'Sem acesso']);
        $user = User::create(['name'=>'Normal','email'=>'normal@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$role->id,'active'=>true]);
        $this->actingAs($user)->get('/admin/usuarios')->assertForbidden();
    }
}
