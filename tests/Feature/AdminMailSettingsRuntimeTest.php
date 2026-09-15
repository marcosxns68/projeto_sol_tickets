<?php

namespace Tests\Feature;

use App\Mail\SmtpTestMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminMailSettingsRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_request_applies_database_mail_settings_before_controller_runs(): void
    {
        $admin = $this->makeAdministrator();
        app(MailSettings::class)->save([
            'host' => 'db-mail.example.test',
            'port' => 465,
            'username' => 'db-user@example.test',
            'password' => 'runtime-test-secret',
            'encryption' => 'ssl',
            'from_address' => 'db-from@example.test',
            'from_name' => 'DB Mail',
        ]);

        config(['mail.mailers.smtp.host' => 'before-request.example.test']);

        $this->actingAs($admin)
            ->get('/admin/configuracoes/email')
            ->assertOk();

        $this->assertSame('db-mail.example.test', config('mail.mailers.smtp.host'));
    }

    public function test_super_admin_can_send_test_email_and_route_is_throttled(): void
    {
        Mail::fake();
        $admin = $this->makeAdministrator();

        app(MailSettings::class)->save([
            'host' => 'mail.example.test',
            'port' => 465,
            'username' => 'tickets@example.test',
            'password' => 'mail-test-secret',
            'encryption' => 'ssl',
            'from_address' => 'tickets@example.test',
            'from_name' => 'Sutoorii Tickets',
        ]);

        $this->actingAs($admin)
            ->post('/admin/configuracoes/email/testar', ['test_email' => 'destino@example.test'])
            ->assertRedirect('/admin/configuracoes/email')
            ->assertSessionHas('success');

        Mail::assertSent(SmtpTestMail::class, fn ($mail) => $mail->hasTo('destino@example.test'));

        $route = app('router')->getRoutes()->getByName('admin.settings.mail.test');
        $this->assertNotNull($route);
        $this->assertContains('throttle:3,1', $route->gatherMiddleware());
    }

    private function makeAdministrator(): User
    {
        $manageUsers = Permission::firstOrCreate(['key' => 'users.manage'], ['name' => 'Gerenciar usuários', 'group' => 'users']);
        $managePermissions = Permission::firstOrCreate(['key' => 'permissions.manage'], ['name' => 'Gerenciar permissões', 'group' => 'permissions']);
        $role = Role::create(['name' => 'Super Administrador runtime mail']);
        $role->permissions()->attach([$manageUsers->id, $managePermissions->id]);

        return User::create([
            'name' => 'Super Admin Runtime',
            'email' => 'superadmin-runtime@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'TestPass123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }
}
