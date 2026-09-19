<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WhatsAppConnection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminWhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(?string $role = 'Super Admin'): User
    {
        return User::create([
            'name' => 'Conta WhatsApp',
            'email' => uniqid('whatsapp').'@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'Teste123456',
            'active' => true,
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
    }

    public function test_configuration_page_is_super_admin_only_and_secrets_are_encrypted(): void
    {
        $admin = $this->user();
        $this->actingAs($this->user('Gestor'))
            ->get('/admin/configuracoes/whatsapp')->assertForbidden();

        $this->actingAs($admin)->get('/admin/configuracoes/whatsapp')
            ->assertOk()->assertSee('WhatsApp')->assertSee('Evolution API');

        $this->actingAs($admin)->patch('/admin/configuracoes/whatsapp', [
            'base_url' => 'https://evolution.example.test',
            'instance' => 'sutoorii-tickets',
            'api_key' => 'segredo-whatsapp-teste',
        ])->assertRedirect();

        $encrypted = \App\Models\Setting::getValue('whatsapp.evolution.api_key');
        $this->assertNotSame('segredo-whatsapp-teste', $encrypted);
        $this->assertSame('segredo-whatsapp-teste', Crypt::decryptString($encrypted));

        $this->actingAs($admin)->get('/admin/configuracoes/whatsapp')
            ->assertOk()->assertDontSee('segredo-whatsapp-teste');
    }

    public function test_qr_code_is_fetched_server_side_and_only_rendered_for_super_admin(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->patch('/admin/configuracoes/whatsapp', [
            'base_url' => 'https://evolution.example.test',
            'instance' => 'sutoorii-tickets',
            'api_key' => 'segredo-whatsapp-teste',
        ])->assertRedirect();

        Http::fake([
            'evolution.example.test/instance/connect/sutoorii-tickets' =>
                Http::response(['base64' => 'data:image/png;base64,'.base64_encode('teste-png'), 'code' => '2@teste'], 200),
        ]);
        $this->actingAs($admin)->post('/admin/configuracoes/whatsapp/qrcode')
            ->assertOk()
            ->assertSee('data:image/png;base64,', false)
            ->assertDontSee('segredo-whatsapp-teste');

        Http::assertSent(fn ($request) =>
            $request->hasHeader('apikey', 'segredo-whatsapp-teste')
            && $request->url() === 'https://evolution.example.test/instance/connect/sutoorii-tickets');

        $this->actingAs($this->user('Gestor'))
            ->post('/admin/configuracoes/whatsapp/qrcode')->assertForbidden();
    }

    public function test_instance_name_with_spaces_or_dots_is_accepted_as_registered_in_evolution(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->patch('/admin/configuracoes/whatsapp', [
            'base_url' => 'https://evolution.example.test',
            'instance' => '  Sutoorii Tickets.01  ',
            'api_key' => 'segredo-whatsapp-teste',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(
            'Sutoorii Tickets.01',
            \App\Models\Setting::getValue('whatsapp.evolution.instance')
        );

        Http::fake([
            'evolution.example.test/instance/connect/*' =>
                Http::response(['base64' => 'data:image/png;base64,'.base64_encode('teste-png')], 200),
        ]);

        $this->actingAs($admin)->post('/admin/configuracoes/whatsapp/qrcode')
            ->assertOk()->assertSee('data:image/png;base64,', false);
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), 'Sutoorii%20Tickets.01'));
    }

    public function test_invalid_instance_name_shows_clear_portuguese_error_instead_of_validation_regex(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->from('/admin/configuracoes/whatsapp')
            ->patch('/admin/configuracoes/whatsapp', [
                'base_url' => 'https://evolution.example.test',
                'instance' => 'nome/instancia',
                'api_key' => 'segredo-whatsapp-teste',
            ])
            ->assertRedirect('/admin/configuracoes/whatsapp')
            ->assertSessionHasErrors(['instance' => 'O nome da instância contém caracteres não permitidos. Use letras, números, espaços, pontos, hífen ou sublinhado.']);

        $this->actingAs($admin)->get('/admin/configuracoes/whatsapp')
            ->assertOk()->assertDontSee('validation.regex');
    }

}
