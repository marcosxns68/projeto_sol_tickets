<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        $siteKey = trim((string) config('turnstile.site_key', ''));
        $secretKey = trim((string) config('turnstile.secret_key', ''));

        // An unconfigured installation remains functional until its production keys are provisioned.
        if ($siteKey === '' && $secretKey === '') {
            return $next($request);
        }

        $token = $request->input('cf-turnstile-response');
        if ($siteKey === '' || $secretKey === '' || !is_string($token) || $token === '' || strlen($token) > 2048) {
            throw ValidationException::withMessages(['turnstile' => 'Não foi possível validar a verificação de segurança. Atualize a página e tente novamente.']);
        }

        try {
            $response = Http::asForm()->timeout(7)->connectTimeout(3)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                ['secret' => $secretKey, 'response' => $token]
            );
            $result = $response->successful() ? $response->json() : null;
            $expectedHost = trim((string) config('turnstile.hostname', ''));
            $valid = is_array($result)
                && ($result['success'] ?? false) === true
                && ($expectedHost === '' || hash_equals($expectedHost, (string) ($result['hostname'] ?? '')));
        } catch (\Throwable $exception) {
            report($exception);
            $valid = false;
        }

        if (!$valid) {
            throw ValidationException::withMessages(['turnstile' => 'Não foi possível validar a verificação de segurança. Atualize a página e tente novamente.']);
        }

        return $next($request);
    }
}
