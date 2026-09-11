<?php

namespace App\Http\Middleware;

use App\Models\ConnectedSystem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Chave de integração ausente.'], 401);
        }

        $integration = ConnectedSystem::query()
            ->where('api_token_hash', hash('sha256', $token))
            ->first();

        if (!$integration) {
            return response()->json(['message' => 'Chave de integração inválida.'], 401);
        }

        if (!$integration->active) {
            return response()->json(['message' => 'Integração inativa.'], 403);
        }

        $externalUserId = trim((string) $request->header('X-External-User-Id'));
        $role = strtolower(trim((string) $request->header('X-External-User-Role', 'user')));

        if ($externalUserId === '') {
            return response()->json(['message' => 'Identificador do usuário externo é obrigatório.'], 422);
        }

        if (!in_array($role, ['user', 'manager'], true)) {
            return response()->json(['message' => 'Papel do usuário externo inválido.'], 422);
        }

        $request->attributes->set('integration', $integration);
        $request->attributes->set('external_user_id', $externalUserId);
        $request->attributes->set('external_user_role', $role);

        return $next($request);
    }
}
