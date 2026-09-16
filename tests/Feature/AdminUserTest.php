<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmailPtBrNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
            'permissions'=>[$reassign->id=>'yes'],
        ])->assertRedirect();

        $this->assertSame('Alvo Atualizado',$target->fresh()->name);
        $this->assertDatabaseHas('user_permission_overrides',['user_id'=>$target->id,'permission_id'=>$reassign->id,'effect'=>'allow']);
        $this->assertTrue($target->fresh()->hasPermission('tickets.reassign'));
    }

    public function test_permission_editor_shows_only_effective_yes_or_no(): void
    {
        [$admin] = $this->makeAdministrator();
        $inherited = Permission::firstOrCreate(['key'=>'tickets.inherited'], ['name'=>'Permissão herdada','group'=>'tickets']);
        $missing = Permission::firstOrCreate(['key'=>'tickets.missing'], ['name'=>'Permissão ausente','group'=>'tickets']);

        $role = Role::create(['name'=>'Operador']);
        $role->permissions()->attach($inherited->id);
        $target = User::create([
            'name'=>'Operador teste',
            'email'=>'operador@sutoorii.com',
            'email_verified_at'=>now(),
            'password'=>'SenhaTeste123',
            'role_id'=>$role->id,
            'active'=>true,
        ]);

        $response = $this->actingAs($admin)->get('/admin/usuarios/'.$target->id.'/editar');

        $response->assertOk()
            ->assertSee('Permissões individuais')
            ->assertSee('Sim')
            ->assertSee('Não')
            ->assertDontSee('Herdada do cargo')
            ->assertDontSee('Concedida')
            ->assertDontSee('Negada');

        $this->assertTrue($target->hasPermission($inherited->key));
        $this->assertFalse($target->hasPermission($missing->key));
    }

    public function test_matching_yes_no_values_keep_role_inheritance_internally(): void
    {
        [$admin] = $this->makeAdministrator();
        $inherited = Permission::firstOrCreate(['key'=>'tickets.role_yes'], ['name'=>'Permitida pelo cargo','group'=>'tickets']);
        $notInherited = Permission::firstOrCreate(['key'=>'tickets.role_no'], ['name'=>'Negada pelo cargo','group'=>'tickets']);

        $role = Role::create(['name'=>'Atendente']);
        $role->permissions()->attach($inherited->id);
        $target = User::create([
            'name'=>'Atendente teste',
            'email'=>'atendente@sutoorii.com',
            'email_verified_at'=>now(),
            'password'=>'SenhaTeste123',
            'role_id'=>$role->id,
            'active'=>true,
        ]);

        $this->actingAs($admin)->patch('/admin/usuarios/'.$target->id, [
            'name'=>$target->name,
            'email'=>$target->email,
            'role_id'=>$role->id,
            'active'=>1,
            'permissions'=>[
                $inherited->id=>'yes',
                $notInherited->id=>'no',
            ],
        ])->assertRedirect();

        $this->assertDatabaseMissing('user_permission_overrides',['user_id'=>$target->id,'permission_id'=>$inherited->id]);
        $this->assertDatabaseMissing('user_permission_overrides',['user_id'=>$target->id,'permission_id'=>$notInherited->id]);
        $this->assertTrue($target->fresh()->hasPermission($inherited->key));
        $this->assertFalse($target->fresh()->hasPermission($notInherited->key));
    }

    public function test_user_without_permission_cannot_access_admin_users(): void
    {
        $role = Role::create(['name'=>'Sem acesso']);
        $user = User::create(['name'=>'Normal','email'=>'normal@sutoorii.com','email_verified_at'=>now(),'password'=>'SenhaTeste123','role_id'=>$role->id,'active'=>true]);
        $this->actingAs($user)->get('/admin/usuarios')->assertForbidden();
    }

    public function test_super_admin_can_resend_verification_email_to_unverified_user(): void
    {
        Notification::fake();

        [$admin] = $this->makeAdministrator();
        $target = User::create([
            'name'=>'Conta pendente',
            'email'=>'pendente@sutoorii.com',
            'email_verified_at'=>null,
            'password'=>'SenhaTeste123',
            'active'=>true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/usuarios/'.$target->id.'/editar')
            ->assertOk()
            ->assertSee('Conta ainda não confirmada')
            ->assertSee('Reenviar e-mail de confirmação');

        $this->actingAs($admin)
            ->post('/admin/usuarios/'.$target->id.'/reenviar-confirmacao')
            ->assertRedirect('/admin/usuarios/'.$target->id.'/editar');

        Notification::assertSentTo($target, VerifyEmailPtBrNotification::class);
        $this->assertDatabaseHas('audit_logs', [
            'user_id'=>$admin->id,
            'auditable_type'=>User::class,
            'auditable_id'=>$target->id,
            'event'=>'user.verification_resent',
        ]);
    }

    public function test_user_without_full_admin_permissions_cannot_resend_verification_email(): void
    {
        Notification::fake();

        $manageUsers = Permission::firstOrCreate(['key'=>'users.manage'], ['name'=>'Gerenciar usuários','group'=>'users']);
        $role = Role::create(['name'=>'Gestor parcial']);
        $role->permissions()->attach($manageUsers->id);
        $actor = User::create([
            'name'=>'Gestor parcial',
            'email'=>'gestor@sutoorii.com',
            'email_verified_at'=>now(),
            'password'=>'SenhaTeste123',
            'role_id'=>$role->id,
            'active'=>true,
        ]);
        $target = User::create([
            'name'=>'Conta pendente',
            'email'=>'pendente2@sutoorii.com',
            'email_verified_at'=>null,
            'password'=>'SenhaTeste123',
            'active'=>true,
        ]);

        $this->actingAs($actor)
            ->post('/admin/usuarios/'.$target->id.'/reenviar-confirmacao')
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_verified_user_does_not_receive_another_verification_email(): void
    {
        Notification::fake();

        [$admin] = $this->makeAdministrator();
        $target = User::create([
            'name'=>'Conta confirmada',
            'email'=>'confirmada@sutoorii.com',
            'email_verified_at'=>now(),
            'password'=>'SenhaTeste123',
            'active'=>true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/usuarios/'.$target->id.'/editar')
            ->assertOk()
            ->assertDontSee('Reenviar e-mail de confirmação');

        $this->actingAs($admin)
            ->post('/admin/usuarios/'.$target->id.'/reenviar-confirmacao')
            ->assertSessionHasErrors('verification');

        Notification::assertNothingSent();
    }

    private function makeAdministrator(): array
    {
        $manageUsers = Permission::firstOrCreate(['key'=>'users.manage'], ['name'=>'Gerenciar usuários','group'=>'users']);
        $managePermissions = Permission::firstOrCreate(['key'=>'permissions.manage'], ['name'=>'Gerenciar permissões','group'=>'permissions']);
        $role = Role::create(['name'=>'Super Administrador teste']);
        $role->permissions()->attach([$manageUsers->id,$managePermissions->id]);

        $admin = User::create([
            'name'=>'Super Admin',
            'email'=>'superadmin@sutoorii.com',
            'email_verified_at'=>now(),
            'password'=>'SenhaTeste123',
            'role_id'=>$role->id,
            'active'=>true,
        ]);

        return [$admin, $role];
    }
}
