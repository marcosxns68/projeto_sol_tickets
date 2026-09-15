<?php

namespace App\Http\Middleware;

use App\Services\MailSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApplyMailSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (Schema::hasTable('settings')) {
                app(MailSettings::class)->apply();
            }
        } catch (Throwable) {
            // Se o banco ainda não estiver disponível, o sistema continua usando o SMTP do .env.
        }

        return $next($request);
    }
}
