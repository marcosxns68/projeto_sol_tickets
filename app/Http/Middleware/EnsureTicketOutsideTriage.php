<?php

namespace App\Http\Middleware;

use App\Models\Ticket;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTicketOutsideTriage
{
    public function handle(Request $request, Closure $next): Response
    {
        $ticket = $request->route('ticket');
        if ($ticket instanceof Ticket && $ticket->isInTriage()) {
            return redirect()->route('tickets.show', $ticket)->with(
                'error',
                'Este ticket está na Triagem. Encaminhe-o para um departamento antes de editar. Notas internas continuam disponíveis.'
            );
        }

        return $next($request);
    }
}
