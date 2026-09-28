<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MailQueue;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function __construct(private Tenant $t) {}

    public function index()
    {
        if (! Gate::allows('manage-company')) {
            return view('password-settings');
        }
        Gate::authorize('manage-company');

        return view('settings', ['mailQueue' => $this->t->query('email_logs')->whereIn('status', ['pending', 'failed', 'sending'])->orderBy('id')->paginate(20, ['*'], 'mail_page'), 'staff' => User::where('company_id', $this->t->id())->whereNull('deleted_at')->get(), 'smtp' => $this->t->company->smtp ?? [], 'audit' => $this->t->query('audit_logs')->orderByDesc('id')->paginate(15)]);
    }

    public function save(Request $r)
    {
        Gate::authorize('manage-company');
        $section = $r->input('section');
        $company = $this->t->company;
        if ($section === 'company') {
            $d = $r->validate(['name' => 'required|string|max:160', 'legal_name' => 'nullable|string|max:160', 'document' => 'nullable|string|max:30', 'phone' => 'nullable|string|max:30', 'whatsapp' => 'nullable|string|max:30', 'email' => 'nullable|email|max:180', 'address' => 'nullable|string|max:255', 'timezone' => 'required|timezone', 'currency' => 'required|in:BRL,USD,EUR,GBP', 'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:1024']);
            if ($company->currency !== $d['currency'] && ($this->t->query('sales')->exists() || $this->t->query('accounts')->exists())) {
                throw ValidationException::withMessages(['currency' => 'A moeda não pode ser alterada depois dos primeiros lançamentos financeiros.']);
            }
            if ($r->hasFile('logo')) {
                $d['logo'] = $r->file('logo')->store('companies/'.$company->id, 'local');
            } else {
                unset($d['logo']);
            }
            $company->update($d);
        } elseif ($section === 'modules') {
            Gate::authorize('platform');
            $r->validate(['modules' => 'nullable|array', 'modules.*' => ['string', Rule::in(array_keys(config('poseitech.modules')))]]);
            $modules = array_values(array_unique($r->input('modules', [])));
            foreach (['delivery' => ['orders'], 'orders' => ['sales', 'products'], 'stock' => ['products'], 'sales' => ['products'], 'credit' => ['sales', 'customers'], 'campaigns' => ['customers'], 'portal' => ['customers'], 'loyalty' => ['customers']] as $module => $dependencies) {
                if (in_array($module, $modules) && array_diff($dependencies, $modules)) {
                    $modules = array_values(array_unique(array_merge($modules, $dependencies)));
                }
            }
            if (in_array('sales', $modules) && ! in_array('products', $modules)) {
                $modules[] = 'products';
            }
            if (! in_array('cash', $modules) && $this->t->query('cash_registers')->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['modules' => 'Feche o caixa antes de desativar o módulo.']);
            }
            $company->update(['modules' => $modules]);
        } elseif ($section === 'smtp') {
            $d = $r->validate(['host' => 'required|string|max:200', 'port' => 'required|integer|min:1|max:65535', 'username' => 'nullable|string|max:200', 'password' => 'nullable|string|max:500', 'encryption' => 'required|in:tls,ssl', 'from' => 'required|email|max:180', 'from_name' => 'required|string|max:160']);
            if (empty($d['password'])) {
                $d['password'] = $company->smtp['password'] ?? null;
            }
            $company->update(['smtp' => $d]);
        } elseif ($section === 'loyalty') {
            $d = $r->validate(['loyalty_mode' => 'required|in:points,purchases,cashback', 'loyalty_rate' => 'required|numeric|min:0|max:100', 'birthday_automation' => 'nullable|boolean']);
            $d['birthday_automation'] = $r->boolean('birthday_automation');
            $company->update(['settings' => array_merge($company->settings ?? [], $d)]);
        } else {
            abort(404);
        }
        $this->t->audit('settings.'.$section, 'companies', $company->id);

        return back()->with('success', 'Configurações salvas.');
    }

    public function staff(Request $r, ?int $id = null)
    {
        Gate::authorize('manage-company');
        $user = $id ? User::where('company_id', $this->t->id())->whereNull('deleted_at')->findOrFail($id) : null;
        $d = $r->validate(['name' => 'required|string|max:160', 'email' => ['required', 'email', 'max:180', Rule::unique('users')->ignore($id)],
            'password' => [$id ? 'nullable' : 'required', Password::min(10)->letters()->numbers()], 'role' => 'required|in:admin,staff', 'active' => 'nullable|boolean',
            'permissions' => 'nullable|array', 'permissions.*' => ['string', Rule::in(collect(array_keys(config('poseitech.modules')))->flatMap(fn ($m) => [$m.'.read', $m.'.write'])->all())]]);
        $d['active'] = $r->boolean('active');
        $d['permissions'] = $d['permissions'] ?? [];
        if (empty($d['password'])) {
            unset($d['password']);
        } else {
            $d['must_change_password'] = true;
        }
        DB::transaction(function () use ($user, $d) {
            $this->t->lock();
            if ($user && $user->role === 'admin' && (! $d['active'] || $d['role'] !== 'admin') && User::where('company_id', $this->t->id())->where('role', 'admin')->where('active', true)->count() <= 1) {
                abort(422, 'Mantenha ao menos um administrador ativo.');
            }
            if ($user) {
                $user->update($d);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } else {
                $this->t->limit('users');
                $user = User::create($d + ['company_id' => $this->t->id()]);
            }
            $this->t->audit('staff.saved', 'users', $user->id);
        });

        return back()->with('success', 'Usuário salvo.');
    }

    public function staffAction(Request $r, int $id)
    {
        Gate::authorize('manage-company');
        $d = $r->validate(['action' => 'required|in:activate,deactivate,delete']);
        DB::transaction(function () use ($id, $d) {
            $this->t->lock();
            $user = User::where('company_id', $this->t->id())->whereNull('deleted_at')->findOrFail($id);
            if ($d['action'] !== 'activate') {
                if ($user->id === auth()->id() || ($user->role === 'admin' && $user->active && User::where('company_id', $this->t->id())->whereNull('deleted_at')->where('role', 'admin')->where('active', true)->count() <= 1)) {
                    throw ValidationException::withMessages(['staff' => 'Não é possível remover o próprio acesso nem o último administrador ativo.']);
                }
            }
            $user->forceFill(['active' => $d['action'] === 'activate', 'deleted_at' => $d['action'] === 'delete' ? now() : null, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $id)->delete();
            $this->t->audit('staff.'.$d['action'], 'users', $id);
        });

        return back()->with('success', 'Equipe atualizada.');
    }

    public function mailAction(Request $r, MailQueue $queue)
    {
        Gate::authorize('manage-company');
        $d = $r->validate(['action' => 'required|in:clear,retry,send,process', 'id' => 'nullable|integer']);
        if ($d['action'] === 'clear') {
            $count = $this->t->query('email_logs')->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->t->audit('mail.cleared', 'email_logs', null, null, ['count' => $count]);

            return back()->with('success', 'Fila limpa. Envios já iniciados não são interrompidos.');
        }
        if (! $this->t->company->smtp) {
            throw ValidationException::withMessages(['smtp' => 'Configure o SMTP antes de enviar.']);
        }
        if ($d['action'] === 'retry') {
            $this->t->query('email_logs')->where('status', 'failed')->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);
            $this->t->audit('mail.retry_all');

            return back()->with('mail_run', true)->with('success', 'Processando pendentes. Mantenha esta página aberta; o cron continua a fila se você sair.');
        }
        $email = $d['action'] === 'send' ? $this->t->find('email_logs', (int) ($d['id'] ?? 0)) : $this->t->query('email_logs')->where('status', 'pending')->orderBy('id')->first();
        $status = $email ? $queue->send($email->id, $this->t->company, $d['action'] === 'send') : null;
        if ($d['action'] === 'process') {
            return response()->json(['processed' => (bool) $email, 'status' => $status]);
        }
        $this->t->audit('mail.manual_send', 'email_logs', $email->id);

        return back()->with('success', 'Resultado da tentativa: '.$status.'.');
    }
}
