<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function index()
    {
        Gate::authorize('platform');

        return view('admin', ['companies' => Company::with('plan')->orderByDesc('id')->paginate(20), 'plans' => Plan::all(),
            'counts' => ['Empresas' => Company::count(), 'Ativas' => Company::where('status', 'active')->count(), 'Bloqueadas' => Company::where('status', 'blocked')->count(), 'Usuários' => User::count()],
            'logs' => DB::table('audit_logs')->orderByDesc('id')->limit(30)->get(),
            'platformSettings' => DB::table('platform_settings')->pluck('value', 'key'),
            'usage' => DB::table('companies')->leftJoin('sales', 'sales.company_id', '=', 'companies.id')->selectRaw('companies.id, companies.name, COUNT(sales.id) as sales, COALESCE(SUM(sales.total),0) as volume')->groupBy('companies.id', 'companies.name')->limit(100)->get(),
            'newCompanies' => Company::where('created_at', '>=', now()->subDays(30))->count()]);
    }

    public function company(Request $r, ?int $id = null)
    {
        Gate::authorize('platform');
        $d = $r->validate(['name' => 'required|string|max:160', 'plan_id' => 'required|exists:plans,id', 'status' => 'required|in:active,inactive,blocked', 'subscription_until' => 'nullable|date',
            'owner_name' => [$id ? 'nullable' : 'required', 'string', 'max:160'], 'owner_email' => [$id ? 'nullable' : 'required', 'email', 'unique:users,email'],
            'owner_password' => [$id ? 'nullable' : 'required', Password::min(10)->letters()->numbers()]]);
        DB::transaction(function () use ($d, $id) {
            $company = $id ? Company::findOrFail($id) : new Company;
            $company->fill(collect($d)->only('name', 'plan_id', 'status', 'subscription_until')->all());
            if (! $id) {
                $company->slug = Str::slug($d['name']).'-'.Str::lower(Str::random(8));
                $company->modules = array_values(array_intersect(config('poseitech.defaults'), Plan::findOrFail($d['plan_id'])->modules));
            }
            $company->save();
            if (! $id) {
                User::create(['company_id' => $company->id, 'role' => 'admin', 'name' => $d['owner_name'], 'email' => $d['owner_email'], 'password' => $d['owner_password']]);
            }
            app(Tenant::class)->company = $company;
            app(Tenant::class)->audit('platform.company_saved', 'companies', $company->id);
        });

        return back()->with('success', 'Empresa salva.');
    }

    public function plan(Request $r, ?int $id = null)
    {
        Gate::authorize('platform');
        $d = $r->validate(['name' => 'required|string|max:80', 'price' => 'required|numeric|min:0|max:99999', 'users_limit' => 'required|integer|min:1|max:100000',
            'customers_limit' => 'required|integer|min:1|max:10000000', 'products_limit' => 'required|integer|min:1|max:1000000', 'campaigns_limit' => 'required|integer|min:0|max:100000',
            'storage_mb' => 'required|integer|min:1|max:1000000', 'modules' => 'required|array', 'modules.*' => [Rule::in(array_keys(config('poseitech.modules')))]]);
        $d['price'] = Tenant::cents($d['price']);
        $plan = $id ? Plan::findOrFail($id) : new Plan;
        $plan->fill($d)->save();
        app(Tenant::class)->audit('platform.plan_saved', 'plans', $plan->id);

        return back()->with('success', 'Plano salvo.');
    }

    public function support(Request $r, int $id)
    {
        Gate::authorize('platform');
        $r->validate(['reason' => 'required|string|min:5|max:255']);
        $company = Company::findOrFail($id);
        app(Tenant::class)->company = $company;
        app(Tenant::class)->audit('support.started', 'companies', $id, null, ['reason' => $r->input('reason')]);
        $r->session()->put('support_company', $id);

        return redirect('/dashboard');
    }

    public function leave(Request $r)
    {
        Gate::authorize('platform');
        app(Tenant::class)->company = Company::find($r->session()->get('support_company'));
        app(Tenant::class)->audit('support.ended');
        $r->session()->forget('support_company');

        return redirect('/admin');
    }

    public function settings(Request $r)
    {
        Gate::authorize('platform');
        $d = $r->validate(['registration_enabled' => 'nullable|boolean', 'trial_days' => 'required|integer|min:1|max:365', 'support_email' => 'required|email|max:180']);
        $d['registration_enabled'] = $r->boolean('registration_enabled') ? '1' : '0';
        DB::transaction(function () use ($d) {
            foreach ($d as $key => $value) {
                DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
            }app(Tenant::class)->audit('platform.settings');
        });

        return back()->with('success','Configurações globais salvas.');
    }
}
