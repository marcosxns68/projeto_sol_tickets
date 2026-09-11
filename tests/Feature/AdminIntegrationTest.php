<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithIntegrationPermission(bool $allowed = true): User
    {
        $role = Role::create(['name' => 'Integrações '.uniqid(), 'active' => true]);
        if ($allowed) {
            $role->permissions()->attach(Permission::where('key', 'integrations.manage')->firstOrFail()->id);
        }

        return User::create([
            'name' => 'Admin Integrações',
            'email' => uniqid('integracao-').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    public function test_authorized_user_can_create_integration_without_company_and_receives_key_once(): void
    {
        $admin = $this->userWithIntegrationPermission();

        $response = $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'Estúdio França',
            'active' => 1,
        ]);

        $response->assertRedirect('/admin/integracoes');
        $token = session('generated_api_token');
        $this->assertIsString($token);
        $this->assertStringStartsWith('st_live_', $token);

        $integration = ConnectedSystem::where('name', 'Estúdio França')->firstOrFail();
        $this->assertNull($integration->company_id);
        $this->assertSame(hash('sha256', $token), $integration->api_token_hash);
        $this->assertNotSame($token, $integration->api_token_hash);

        $this->actingAs($admin)->get('/admin/integracoes')
            ->assertOk()
            ->assertSee('Integrações')
            ->assertDontSee($token);
    }

    public function test_rotating_key_invalidates_old_key_and_new_key_authenticates(): void
    {
        $admin = $this->userWithIntegrationPermission();
        $integration = ConnectedSystem::create(['name' => 'França', 'active' => true]);
        $old = $integration->issueApiToken();

        $response = $this->actingAs($admin)->post('/admin/integracoes/'.$integration->id.'/nova-chave');
        $response->assertRedirect();
        $new = session('generated_api_token');
        $this->assertNotSame($old, $new);

        $headers = fn (string $token) => [
            'Authorization' => 'Bearer '.$token,
            'X-External-User-Id' => '1',
            'X-External-User-Role' => 'user',
        ];

        $this->withHeaders($headers($old))->getJson('/api/v1/tickets')->assertUnauthorized();
        $this->withHeaders($headers($new))->getJson('/api/v1/tickets')->assertOk();
    }

    public function test_user_without_permission_cannot_manage_integrations(): void
    {
        $user = $this->userWithIntegrationPermission(false);
        $this->actingAs($user)->get('/admin/integracoes')->assertForbidden();
    }
}
