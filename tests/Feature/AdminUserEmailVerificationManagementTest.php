<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmailPtBrNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserEmailVerificationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_user_email_resets_email_verification(): void
    {
        $admin = $this->makeAdministrator();
        $target = User::create([
            'name' => 'Usuário Confirmado',
            'email' => 'antigo@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->patch('/admin/usuarios/'.$target->id, [
                'name' => $target->name,
                'email' => 'novo@sutoorii.com',
                'active' => 1,
            ])
            ->assertRedirect('/admin/usuarios/'.$target->id.'/editar');

        $target->refresh();
        $this->assertSame('novo@sutoorii.com', $target->email);
        $this->assertNull($target->email_verified_at);
    }

    public function test_users_list_shows_email_verification_status(): void
    {
        $admin = $this->makeAdministrator();
        User::create([
            'name' => 'Confirmado',
            'email' => 'confirmado@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'active' => true,
        ]);
        User::create([
            'name' => 'Pendente',
            'email' => 'pendente@sutoorii.com',
            'email_verified_at' => null,
            'password' => 'SenhaTeste123',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/usuarios')
            ->assertOk()
            ->assertSee('E-mail confirmado')
            ->assertSee('Não confirmado');
    }

    public function test_super_admin_can_reset_verification_and_resend_email(): void
    {
        Notification::fake();
        $admin = $this->makeAdministrator();
        $target = User::create([
            'name' => 'Confirmação incorreta',
            'email' => 'incorreta@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/usuarios/'.$target->id.'/editar')
            ->assertOk()
            ->assertSee('E-mail confirmado')
            ->assertSee('Redefinir confirmação e reenviar e-mail');

        $this->actingAs($admin)
            ->post('/admin/usuarios/'.$target->id.'/redefinir-confirmacao')
            ->assertRedirect('/admin/usuarios/'.$target->id.'/editar')
            ->assertSessionHas('success');

        $this->assertNull($target->fresh()->email_verified_at);
        Notification::assertSentTo($target, VerifyEmailPtBrNotification::class);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'event' => 'user.verification_reset_and_resent',
        ]);
    }

    private function makeAdministrator(): User
    {
        $manageUsers = Permission::firstOrCreate(
            ['key' => 'users.manage'],
            ['name' => 'Gerenciar usuários', 'group' => 'users']
        );
        $managePermissions = Permission::firstOrCreate(
            ['key' => 'permissions.manage'],
            ['name' => 'Gerenciar permissões', 'group' => 'permissions']
        );
        $role = Role::create(['name' => 'Super Administrador confirmação']);
        $role->permissions()->attach([$manageUsers->id, $managePermissions->id]);

        return User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin-confirmacao@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }
}
