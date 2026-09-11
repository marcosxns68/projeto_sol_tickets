<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIntegrationKeyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $allowed = true): User
    {
        $role = Role::create(['name' => 'Integrações '.uniqid(), 'active' => true]);

        if ($allowed) {
            $permission = Permission::where('key', 'integrations.manage')->firstOrFail();
            $role->permissions()->attach($permission->id);
        }

        return User::create([
            'name' => 'Admin Integrações',
            'email' => uniqid('integracoes-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    public function test_authorized_user_can_open_integrations_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/integracoes')
            ->assertOk()
            ->assertSee('Integrações')
            ->assertSee('Nova integração');
    }

    public function test_authorized_user_can_create_integration_and_receives_api_key_once(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'Estúdio França',
            'base_url' => 'https://estudiofranca.example',
            'active' => '1',
        ]);

        $response->assertRedirect('/admin/integracoes');

        $token = session('generated_api_token');
        $this->assertIsString($token);
        $this->assertStringStartsWith('st_live_', $token);

        $integration = ConnectedSystem::where('name', 'Estúdio França')->firstOrFail();
        $this->assertSame(hash('sha256', $token), $integration->api_token_hash);
        $this->assertNotSame($token, $integration->api_token_hash);

        $this->actingAs($admin)->get('/admin/integracoes')
            ->assertOk()
            ->assertSee($token);

        $this->actingAs($admin)->get('/admin/integracoes')
            ->assertOk()
            ->assertDontSee($token);
    }

    public function test_authorized_user_can_rotate_integration_key(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'Sistema Teste',
            'active' => '1',
        ])->assertRedirect('/admin/integracoes');

        $integration = ConnectedSystem::where('name', 'Sistema Teste')->firstOrFail();
        $oldHash = $integration->api_token_hash;

        $this->actingAs($admin)
            ->post('/admin/integracoes/'.$integration->id.'/nova-chave')
            ->assertRedirect('/admin/integracoes');

        $newToken = session('generated_api_token');
        $this->assertIsString($newToken);
        $this->assertStringStartsWith('st_live_', $newToken);
        $this->assertNotSame($oldHash, $integration->fresh()->api_token_hash);
        $this->assertSame(hash('sha256', $newToken), $integration->fresh()->api_token_hash);
    }

    public function test_user_without_permission_cannot_manage_integrations(): void
    {
        $this->actingAs($this->admin(false))
            ->get('/admin/integracoes')
            ->assertForbidden();
    }
}
