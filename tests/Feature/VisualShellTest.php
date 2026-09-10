<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_workspace_uses_helpdesk_sidebar_and_ticket_table(): void
    {
        $permissionKeys = [
            'tickets.create',
            'tickets.view_department',
            'users.manage',
            'departments.manage',
            'roles.manage',
        ];

        $role = Role::create(['name' => 'Administrador visual', 'active' => true]);
        $permissionIds = [];
        foreach ($permissionKeys as $key) {
            $permissionIds[] = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => $key, 'group' => explode('.', $key)[0]]
            )->id;
        }
        $role->permissions()->attach($permissionIds);

        $department = Department::create(['name' => 'Suporte', 'active' => true]);
        $user = User::create([
            'name' => 'Admin',
            'email' => 'visual@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'department_id' => $department->id,
            'active' => true,
        ]);

        $response = $this->actingAs($user)->get('/minha-caixa');

        $response->assertOk()
            ->assertSee('class="app-sidebar"', false)
            ->assertSee('class="workspace-main"', false)
            ->assertSee('class="tickets-table"', false)
            ->assertSee('Minha Caixa')
            ->assertSee('Meu Departamento')
            ->assertSee('Usuários')
            ->assertSee('Departamentos')
            ->assertSee('Cargos')
            ->assertSee('Número')
            ->assertSee('Título')
            ->assertSee('Status')
            ->assertSee('Prioridade')
            ->assertSee('Responsável');
    }

    public function test_styles_define_dark_sutoorii_sidebar_and_light_workspace(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString('--sidebar-bg:', $css);
        $this->assertStringContainsString('--workspace-bg:', $css);
        $this->assertStringContainsString('.app-sidebar', $css);
        $this->assertStringContainsString('.workspace-main', $css);
        $this->assertStringContainsString('.tickets-table', $css);
        $this->assertStringNotContainsString('@media(prefers-color-scheme:dark)', $css);
    }
}
