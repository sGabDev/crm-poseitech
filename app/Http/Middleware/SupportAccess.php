<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Tenant;
use Closure;
use Illuminate\Http\Request;

class SupportAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && $user->active, 403);
        $id = $user->role === 'super' ? $request->session()->get('support_company') : $user->company_id;
        if (! $id && $user->role === 'super') {
            return redirect('/admin/tickets');
        }
        $company = Company::with('plan')->findOrFail($id);
        // Companies with expired subscriptions can still contact support.
        app(Tenant::class)->company = $company;
        view()->share('company', $company);
        view()->share('tenant', app(Tenant::class));

        return $next($request);
    }
}
