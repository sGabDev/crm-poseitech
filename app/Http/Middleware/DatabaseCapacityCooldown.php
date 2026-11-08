<?php

namespace App\Http\Middleware;

use App\Services\DatabaseCapacity;
use Closure;
use Illuminate\Http\Request;

class DatabaseCapacityCooldown
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('up') && ! DatabaseCapacity::recoverIfAvailable()) {
            return DatabaseCapacity::response($request);
        }

        return $next($request);
    }
}
