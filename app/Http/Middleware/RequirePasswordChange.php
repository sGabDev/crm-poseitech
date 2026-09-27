<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->must_change_password && ! $request->is('password/change', 'logout')) {
            return redirect('/password/change');
        }

        return $next($request);
    }
}
