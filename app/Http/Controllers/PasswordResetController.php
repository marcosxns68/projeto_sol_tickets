<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MailSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class PasswordResetController extends Controller
{
    public function requestForm()
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            // A recuperação acontece antes do login: aplicar a mesma configuração
            // SMTP administrativa usada nos demais e-mails do Tickets.
            app(MailSettings::class)->apply();

            if (app()->environment('production') && config('mail.default') !== 'smtp') {
                Log::error('Recuperação de senha: transporte de e-mail não é SMTP.', [
                    'mailer' => (string) config('mail.default'),
                ]);

                return back()->withInput($request->only('email'))->withErrors([
                    'email' => 'O serviço de recuperação de senha está temporariamente indisponível. Tente novamente mais tarde.',
                ]);
            }

            // Evita reutilizar uma conexão SMTP criada com configuração antiga.
            app('mail.manager')->purge('smtp');
            $status = Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            // Nunca registrar e-mail, token de redefinição ou credenciais SMTP.
            Log::error('Falha ao processar envio de recuperação de senha.', [
                'exception_class' => get_class($exception),
                'exception_code' => (string) $exception->getCode(),
            ]);

            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'O serviço de recuperação de senha está temporariamente indisponível. Tente novamente mais tarde.',
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Aguarde um pouco antes de solicitar outro link.']);
        }

        // A mesma mensagem é exibida para e-mails existentes ou não, evitando
        // revelar quais endereços possuem conta no sistema.
        return back()->with(
            'success',
            'Se existir uma conta cadastrada com esse e-mail, enviaremos um link de redefinição de senha.'
        );
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function reset(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(10)->mixedCase()->numbers(),
            ],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Senha redefinida. Você já pode entrar.')
            : back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'O link é inválido ou expirou. Solicite um novo.']);
    }
}
