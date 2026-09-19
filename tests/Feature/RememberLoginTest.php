<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RememberLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_offers_stay_signed_in_option(): void
    {
        $this->get('/entrar')
            ->assertOk()
            ->assertSee('name="remember"', false)
            ->assertSee('Permanecer conectado neste dispositivo');
    }

    public function test_remember_option_queues_persistent_login_cookie(): void
    {
        $user = User::create([
            'name' => 'Usuário Lembrado',
            'email' => 'lembrado@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'active' => true,
        ]);

        $response = $this->post('/entrar', [
            'email' => $user->email,
            'password' => 'SenhaTeste123',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertCookie(Auth::guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_cookie_restores_login_after_primary_session_is_lost(): void
    {
        $user = User::create([
            'name' => 'Usuário Persistente',
            'email' => 'persistente@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'active' => true,
        ]);

        $response = $this->post('/entrar', [
            'email' => $user->email,
            'password' => 'SenhaTeste123',
            'remember' => '1',
        ]);

        $recallerName = Auth::guard()->getRecallerName();
        $recaller = $response->getCookie($recallerName);

        $this->assertNotNull($recaller);
        $this->assertGreaterThan(now()->addDays(300)->timestamp, $recaller->getExpiresTime());

        $this->app['session']->forget(Auth::guard()->getName());
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();

        $this->withCookie($recallerName, $recaller->getValue())
            ->get('/')
            ->assertRedirect(route('boxes.mine'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::guard()->viaRemember());
    }

}
