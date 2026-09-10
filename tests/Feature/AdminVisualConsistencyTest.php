<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminVisualConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::create(['name' => 'Admin visual']);
        $permissions = Permission::whereIn('key', [
            'users.manage',
            'departments.manage',
            'roles.manage',
        ])->pluck('id');
        $role->permissions()->attach($permissions);

        return User::create([
            'name' => 'Admin Visual',
            'email' => 'admin-visual@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    public function test_admin_pages_use_versioned_stylesheet_and_text_only_sidebar_menu(): void
    {
        $admin = $this->admin();

        foreach (['/admin/usuarios', '/admin/departamentos', '/admin/cargos'] as $path) {
            $response = $this->actingAs($admin)->get($path);

            $response->assertOk()
                ->assertSee('class="app-sidebar"', false)
                ->assertSee('class="workspace-main"', false)
                ->assertSee('css/app.css?v=', false)
                ->assertDontSee('nav-glyph', false);
        }
    }

    public function test_service_worker_does_not_precache_css_that_can_become_stale(): void
    {
        $serviceWorker = file_get_contents(public_path('service-worker.js'));

        $this->assertStringNotContainsString("'/css/app.css'", $serviceWorker);
        $this->assertStringContainsString("sutoorii-tickets-v2", $serviceWorker);
    }
}
