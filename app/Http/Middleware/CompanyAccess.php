<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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

        if (! Schema::hasColumn('products', 'deleted_at') || ! Schema::hasColumn('users', 'must_change_password') || ! Schema::hasColumn('users', 'deleted_at') || ! Schema::hasColumn('sales', 'wallet_used') || ! Schema::hasTable('wallet_entries') || ! Schema::hasTable('customer_deposits') || ! Schema::hasTable('flow_entries')) {
            return response()->view('deployment-pending', ['public' => true], 503);
        }

        return $next($request);
    }
}
