<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Tenant;
use Closure;
use Illuminate\Http\Request;

class CompanyAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && $user->active, 403, 'Usuário inativo.');
        $companyId = $user->role === 'super' ? $request->session()->get('support_company') : $user->company_id;
        if (! $companyId && $user->role === 'super') {
            return redirect('/admin');
        }
        $company = Company::with('plan')->findOrFail($companyId);
        abort_unless($company->available(), 403, 'Empresa bloqueada, inativa ou assinatura expirada.');
        app(Tenant::class)->company = $company;
        view()->share('company', $company);
        view()->share('tenant', app(Tenant::class));

        return $next($request);
    }
}
