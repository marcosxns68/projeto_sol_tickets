<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminMailSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_admin_can_open_mail_settings_without_exposing_stored_secret(): void
    {
        $admin = $this->makeAdministrator();
        app(MailSettings::class)->save([
            'host' => 'mail.example.test',
            'port' => 465,
            'username' => 'tickets@example.test',
            'password' => 'test-secret-1',
            'encryption' => 'ssl',
            'from_address' => 'tickets@example.test',
            'from_name' => 'Tickets Teste',
        ]);

        $this->actingAs($admin)
            ->get('/admin/configuracoes/email')
            ->assertOk()
            ->assertSee('Configuração de e-mail')
            ->assertSee('Senha já configurada')
            ->assertDontSee('test-secret-1');
    }

    public function test_partial_admin_cannot_manage_mail_settings(): void
    {
        $manageUsers = Permission::firstOrCreate(['key' => 'users.manage'], ['name' => 'Gerenciar usuários', 'group' => 'users']);
        $role = Role::create(['name' => 'Gestor parcial mail']);
        $role->permissions()->attach($manageUsers->id);
        $actor = User::create([
            'name' => 'Gestor Parcial',
            'email' => 'partial-mail@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'TestPass123',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $this->actingAs($actor)->get('/admin/configuracoes/email')->assertForbidden();
        $this->actingAs($actor)->patch('/admin/configuracoes/email', $this->validPayload())->assertForbidden();
    }

    public function test_full_admin_can_update_mail_settings_without_plain_secret_in_audit(): void
    {
        $admin = $this->makeAdministrator();
        $payload = $this->validPayload();
        $payload['password'] = 'test-secret-2';

        $this->actingAs($admin)
            ->patch('/admin/configuracoes/email', $payload)
            ->assertRedirect('/admin/configuracoes/email')
            ->assertSessionHas('success');

        $encrypted = DB::table('settings')->where('key', 'mail.password_encrypted')->value('value');
        $this->assertNotSame($payload['password'], $encrypted);
        $this->assertSame($payload['password'], Crypt::decryptString($encrypted));

        $audit = DB::table('audit_logs')->where('event', 'mail.settings.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($payload['password'], (string) $audit->new_values);
        $this->assertStringContainsString('"password_changed":true', (string) $audit->new_values);
    }

    private function makeAdministrator(): User
    {
        $manageUsers = Permission::firstOrCreate(['key' => 'users.manage'], ['name' => 'Gerenciar usuários', 'group' => 'users']);
        $managePermissions = Permission::firstOrCreate(['key' => 'permissions.manage'], ['name' => 'Gerenciar permissões', 'group' => 'permissions']);
        $role = Role::create(['name' => 'Super Administrador mail teste']);
        $role->permissions()->attach([$manageUsers->id, $managePermissions->id]);

        return User::create([
            'name' => 'Super Admin Mail',
            'email' => 'superadmin-mail@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'TestPass123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function validPayload(): array
    {
        return [
            'host' => 'mail.sutoorii.test',
            'port' => 465,
            'username' => 'tickets@sutoorii.test',
            'password' => '',
            'encryption' => 'ssl',
            'from_address' => 'tickets@sutoorii.test',
            'from_name' => 'Sutoorii Tickets',
        ];
    }
}
