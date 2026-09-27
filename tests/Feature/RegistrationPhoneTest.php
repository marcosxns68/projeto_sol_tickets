<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_registration_requires_phone_and_saves_it_directly_to_user_profile(): void
    {
        Notification::fake();

        $response = $this->post('/cadastro', [
            'name' => 'Novo Usuário',
            'email' => 'novo@sutoorii.com',
            'whatsapp' => '(15) 99999-8888',
            'password' => 'SenhaTeste123!',
            'password_confirmation' => 'SenhaTeste123!',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::where('email', 'novo@sutoorii.com')->firstOrFail();

        $this->assertSame('5515999998888', $user->whatsapp);
        $this->assertTrue($user->whatsapp_reply_enabled);
        $this->assertSame(
            Role::where('name', 'Usuário interno')->value('id'),
            $user->role_id
        );
    }

    public function test_registration_rejects_missing_or_invalid_phone(): void
    {
        $this->post('/cadastro', [
            'name' => 'Sem Telefone',
            'email' => 'semtelefone@sutoorii.com',
            'password' => 'SenhaTeste123!',
            'password_confirmation' => 'SenhaTeste123!',
        ])->assertSessionHasErrors('whatsapp');

        $this->post('/cadastro', [
            'name' => 'Telefone Inválido',
            'email' => 'invalido@sutoorii.com',
            'whatsapp' => '1234',
            'password' => 'SenhaTeste123!',
            'password_confirmation' => 'SenhaTeste123!',
        ])->assertSessionHasErrors('whatsapp');

        $this->assertDatabaseMissing('users', ['email' => 'semtelefone@sutoorii.com']);
        $this->assertDatabaseMissing('users', ['email' => 'invalido@sutoorii.com']);
    }

    public function test_registration_form_shows_phone_field_and_explains_profile_save(): void
    {
        $this->get('/cadastro')
            ->assertOk()
            ->assertSee('Telefone / WhatsApp')
            ->assertSee('Esse número será salvo automaticamente no seu perfil para os avisos do sistema.')
            ->assertSee('name="whatsapp"', false)
            ->assertSee('autocomplete="tel"', false);
    }
}
