<?php

namespace Tests\Feature;

use App\Models\ConnectedSystem;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private function department(string $name = 'Triagem'): Department
    {
        return Department::create(['name' => $name, 'active' => true]);
    }

    public function test_authorized_user_can_open_integrations_page(): void
    {
        $department = $this->department();

        $this->actingAs($this->admin())
            ->get('/admin/integracoes')
            ->assertOk()
            ->assertSee('Integrações')
            ->assertSee('Nova integração')
            ->assertSee('Departamento padrão')
            ->assertSee($department->name)
            ->assertSee('Configurar webhook')
            ->assertSee('Gerar segredo webhook');
    }

    public function test_authorized_user_can_create_integration_and_receives_api_key_once(): void
    {
        $admin = $this->admin();
        $department = $this->department();

        $response = $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'Estúdio França',
            'base_url' => 'https://estudiofranca.example',
            'department_id' => $department->id,
            'active' => '1',
        ]);

        $response->assertRedirect('/admin/integracoes');

        $token = session('generated_api_token');
        $this->assertIsString($token);
        $this->assertStringStartsWith('st_live_', $token);

        $integration = ConnectedSystem::where('name', 'Estúdio França')->firstOrFail();
        $this->assertSame(hash('sha256', $token), $integration->api_token_hash);
        $this->assertNotSame($token, $integration->api_token_hash);
        $this->assertSame(
            (string) $department->id,
            DB::table('settings')->where('key', 'integration.'.$integration->id.'.department_id')->value('value')
        );

        $this->actingAs($admin)->get('/admin/integracoes')
            ->assertOk()
            ->assertSee($token);

        $this->actingAs($admin)->get('/admin/integracoes')
            ->assertOk()
            ->assertDontSee($token);
    }

    public function test_authorized_user_can_change_default_department(): void
    {
        $admin = $this->admin();
        $triage = $this->department('Triagem');
        $development = $this->department('Desenvolvimento');

        $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'Sistema Teste',
            'department_id' => $triage->id,
            'active' => '1',
        ])->assertRedirect('/admin/integracoes');

        $integration = ConnectedSystem::where('name', 'Sistema Teste')->firstOrFail();

        $this->actingAs($admin)->patch('/admin/integracoes/'.$integration->id, [
            'name' => 'Sistema Teste',
            'department_id' => $development->id,
            'active' => '1',
        ])->assertRedirect('/admin/integracoes');

        $this->assertSame(
            (string) $development->id,
            DB::table('settings')->where('key', 'integration.'.$integration->id.'.department_id')->value('value')
        );
    }

    public function test_authorized_user_can_rotate_integration_key(): void
    {
        $admin = $this->admin();
        $department = $this->department();

        $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'Sistema Teste',
            'department_id' => $department->id,
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


    /** Reproduz o esquema que está atualmente em produção. */
    public function test_webhook_secret_can_be_generated_when_legacy_systems_table_has_no_webhook_secret_column(): void
    {
        $admin = $this->admin();
        $department = $this->department();

        $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'Sistema legado',
            'department_id' => $department->id,
            'active' => '1',
        ])->assertRedirect('/admin/integracoes');

        $integration = ConnectedSystem::where('name', 'Sistema legado')->firstOrFail();

        Schema::table('systems', function ($table): void {
            $table->dropColumn('webhook_secret');
        });

        $this->actingAs($admin)
            ->post('/admin/integracoes/'.$integration->id.'/novo-segredo-webhook')
            ->assertRedirect('/admin/integracoes');

        $secret = session('generated_webhook_secret');
        $this->assertIsString($secret);
        $this->assertStringStartsWith('whsec_', $secret);

        $encrypted = DB::table('settings')
            ->where('key', 'integration.'.$integration->id.'.webhook_secret')
            ->value('value');

        $this->assertIsString($encrypted);
        $this->assertSame($secret, Crypt::decryptString($encrypted));
    }

    public function test_user_without_permission_cannot_manage_integrations(): void
    {
        $this->actingAs($this->admin(false))
            ->get('/admin/integracoes')
            ->assertForbidden();
    }
    public function test_user_can_create_integration_with_domain_without_https_and_optional_webhook(): void
    {
        $department = $this->department();
        $this->actingAs($this->admin())
            ->post('/admin/integracoes', [
                'name' => 'INC Idiomas',
                'base_url' => ' sistema.incidiomas.com ',
                'webhook_url' => '',
                'department_id' => $department->id,
                'active' => '1',
            ])
            ->assertRedirect('/admin/integracoes')
            ->assertSessionHasNoErrors();

        $integration = ConnectedSystem::where('name', 'INC Idiomas')->firstOrFail();
        $this->assertSame('https://sistema.incidiomas.com', $integration->base_url);
        $this->assertNull($integration->webhook_url);
        $this->assertTrue((bool) $integration->api_token_hash);
    }

    public function test_update_accepts_domain_without_scheme_and_normalizes_optional_webhook(): void
    {
        $department = $this->department();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'INC Idiomas',
            'base_url' => 'https://sistema.incidiomas.com',
            'department_id' => $department->id,
            'active' => '1',
        ])->assertRedirect('/admin/integracoes');

        $integration = ConnectedSystem::where('name', 'INC Idiomas')->firstOrFail();

        $this->actingAs($admin)->patch('/admin/integracoes/'.$integration->id, [
            'name' => 'INC Idiomas',
            'base_url' => ' sistema.incidiomas.com/sistema/ ',
            'webhook_url' => ' sistema.incidiomas.com/api/suporte/webhook ',
            'department_id' => $department->id,
            'active' => '1',
        ])->assertRedirect('/admin/integracoes')->assertSessionHasNoErrors();

        $integration->refresh();
        $this->assertSame('https://sistema.incidiomas.com/sistema/', $integration->base_url);
        $this->assertSame('https://sistema.incidiomas.com/api/suporte/webhook', $integration->webhook_url);
    }

    public function test_invalid_urls_show_portuguese_errors_without_creating_integration(): void
    {
        $department = $this->department();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/integracoes', [
            'name' => 'INC Idiomas inválido',
            'base_url' => 'ftp://sistema.incidiomas.com',
            'webhook_url' => 'http://sistema.incidiomas.com/webhook',
            'department_id' => $department->id,
            'active' => '1',
        ])->assertRedirect()
            ->assertSessionHasErrors([
                'base_url' => 'Informe um endereço válido para o sistema, por exemplo https://sistema.exemplo.com.br.',
                'webhook_url' => 'Informe uma URL HTTPS válida para o webhook de retorno, por exemplo https://sistema.exemplo.com.br/webhook.',
            ]);

        $this->assertDatabaseMissing('systems', ['name' => 'INC Idiomas inválido']);
        $response = $this->actingAs($admin)->get('/admin/integracoes')->assertOk();
        $response->assertSee('Pode informar apenas o domínio.')
            ->assertDontSee('validation.url');
    }

    public function test_existing_https_addresses_are_preserved_without_double_prefix(): void
    {
        $department = $this->department();
        $this->actingAs($this->admin())->post('/admin/integracoes', [
            'name' => 'Sistema seguro',
            'base_url' => 'https://sistema.incidiomas.com/sistema',
            'webhook_url' => 'https://sistema.incidiomas.com/webhook',
            'department_id' => $department->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $integration = ConnectedSystem::where('name', 'Sistema seguro')->firstOrFail();
        $this->assertSame('https://sistema.incidiomas.com/sistema', $integration->base_url);
        $this->assertSame('https://sistema.incidiomas.com/webhook', $integration->webhook_url);
    }

}
