<?php

namespace App\Http\Middleware;

use App\Services\DatabaseCapacity;
use Closure;
use Illuminate\Http\Request;

class DatabaseCapacityCooldown
{
    public function handle(Request $request, Closure $next)
    {
        if (DatabaseCapacity::remaining() > 0 && ! $request->is('up')) {
            return DatabaseCapacity::response($request);
        }

        return $next($request);
    }
}
