<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireIntegrationTicketPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $requiresIntegrationPermission = $request->routeIs('integrations.users.search')
            || $request->input('source_mode') === 'integration';

        if ($requiresIntegrationPermission) {
            abort_unless(
                $request->user()?->hasPermission('tickets.create_integration'),
                403
            );
        }

        return $next($request);
    }
}
