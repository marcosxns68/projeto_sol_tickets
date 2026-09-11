<?php

namespace Tests\Feature;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExpiredCsrfSessionTest extends TestCase
{
    public function test_token_mismatch_redirects_to_login_instead_of_rendering_419(): void
    {
        Route::get('/_teste-csrf-expirado', function () {
            throw new TokenMismatchException();
        })->middleware('web');

        $response = $this
            ->from('/entrar')
            ->get('/_teste-csrf-expirado');

        $response->assertRedirect('/entrar');
        $response->assertSessionHas('error', 'Sua sessão expirou. Tente entrar novamente.');
    }

    public function test_login_page_displays_expired_session_message(): void
    {
        $this->withSession([
            'error' => 'Sua sessão expirou. Tente entrar novamente.',
        ])->get('/entrar')
            ->assertOk()
            ->assertSee('Sua sessão expirou. Tente entrar novamente.');
    }
}
