<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponsiveSidebarMobileTest extends TestCase
{
    use RefreshDatabase;

    private function userWithVisualPermissions(): User
    {
        $keys = [
            'tickets.create',
            'tickets.view_department',
            'users.manage',
            'departments.manage',
            'roles.manage',
        ];

        $role = Role::create(['name' => 'Administrador responsivo', 'active' => true]);
        $ids = [];
        foreach ($keys as $key) {
            $ids[] = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => $key, 'group' => explode('.', $key)[0]]
            )->id;
        }
        $role->permissions()->attach($ids);

        $department = Department::create(['name' => 'Suporte', 'active' => true]);

        return User::create([
            'name' => 'Admin Mobile',
            'email' => 'mobile@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'department_id' => $department->id,
            'active' => true,
        ]);
    }

    public function test_authenticated_shell_has_collapsible_sidebar_and_mobile_drawer_controls(): void
    {
        $user = $this->userWithVisualPermissions();

        $response = $this->actingAs($user)->get('/minha-caixa');

        $response->assertOk()
            ->assertSee('id="appSidebar"', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('id="sidebarBackdrop"', false)
            ->assertSee('css/responsive-shell.css?v=', false)
            ->assertSee('js/responsive-shell.js?v=', false)
            ->assertDontSee('<details class="mobile-menu">', false);
    }

    public function test_ticket_box_renders_dedicated_mobile_cards_in_addition_to_desktop_table(): void
    {
        $user = $this->userWithVisualPermissions();

        $response = $this->actingAs($user)->get('/minha-caixa');

        $response->assertOk()
            ->assertSee('class="tickets-table"', false)
            ->assertSee('class="responsive-table desktop-table-wrap"', false)
            ->assertSee('class="mobile-ticket-list"', false)
            ->assertSee('class="mobile-filters"', false);
    }

    public function test_admin_indexes_have_mobile_card_lists_instead_of_relying_on_wide_tables(): void
    {
        $user = $this->userWithVisualPermissions();

        foreach (['/admin/usuarios', '/admin/departamentos', '/admin/cargos'] as $path) {
            $this->actingAs($user)->get($path)
                ->assertOk()
                ->assertSee('class="mobile-admin-list"', false);
        }
    }

    public function test_responsive_assets_define_compact_mobile_layout_and_collapsed_desktop_sidebar(): void
    {
        $css = file_get_contents(public_path('css/responsive-shell.css'));
        $js = file_get_contents(public_path('js/responsive-shell.js'));

        $this->assertStringContainsString('.app-shell.sidebar-collapsed', $css);
        $this->assertStringContainsString('@media (max-width: 900px)', $css);
        $this->assertStringContainsString('.mobile-ticket-list', $css);
        $this->assertStringContainsString('.mobile-admin-list', $css);
        $this->assertStringContainsString('.desktop-table-wrap', $css);
        $this->assertStringContainsString('overflow-x: hidden', $css);
        $this->assertStringContainsString('localStorage', $js);
        $this->assertStringContainsString('sidebar-open', $js);
    }
}
