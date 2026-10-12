<?php

namespace App\Http\Middleware;

use App\Services\Tenant;
use Closure;
use Illuminate\Http\Request;

class WhatsAppModule
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(Tenant::class)->company?->enabled('whatsapp'), 403, 'O módulo Conexão do WhatsApp está desativado.');

        return $next($request);
    }
}
