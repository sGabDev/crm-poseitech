<?php

namespace App\Http\Controllers;

use App\Services\Analytics;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResourceController extends Controller
{
    public function __construct(private Tenant $t) {}

    private function spec(string $resource, bool $write = false): array
    {
        $spec = config('poseitech.resources.'.$resource);
        abort_unless($spec, 404);
        $this->t->authorize($spec['module'], $write);

        return $spec;
    }

    public function index(Request $r, string $resource)
    {
        $spec = $this->spec($resource);
        $q = $this->t->query($resource);
        if ($resource === 'products') {
            $q->whereNull('deleted_at');
        }
        if ($resource === 'customers') {
            $q = app(Analytics::class)->customers($r->string('segment')->toString() ?: 'all');
        }
        if ($r->filled('q')) {
            $q->where(isset($spec['fields']['name']) ? 'name' : 'code', 'like', '%'.mb_substr($r->string('q'), 0, 100).'%');
        }
        $records = $q->orderByDesc('id')->paginate(20)->withQueryString();

        return view('records', compact('resource', 'spec', 'records'));
    }

    public function form(string $resource, ?int $id = null)
    {
        $spec = $this->spec($resource, true);
        $record = $id ? $this->t->find($resource, $id) : null;
        abort_if($resource === 'products' && $record?->deleted_at, 404);
        $customers = $resource === 'coupons' ? $this->t->query('customers')->whereNull('anonymized_at')->orderBy('name')->limit(1000)->get() : collect();

        return view('record-form', compact('resource', 'spec', 'record', 'customers'));
    }

    public function save(Request $r, string $resource, ?int $id = null)
    {
        $spec = $this->spec($resource, true);
        $before = $id ? $this->t->find($resource, $id) : null;
        abort_if($resource === 'products' && $before?->deleted_at, 404);
        abort_if($resource === 'customers' && $before?->anonymized_at, 422, 'Cliente anonimizado.');
        $rules = array_map(fn ($f) => $f[2], $spec['fields']);
        if ($resource === 'coupons') {
            $rules['code'] = ['required', 'alpha_dash', 'max:40', Rule::unique('coupons')->where('company_id', $this->t->id())->ignore($id)];
        }
        $d = $r->validate($rules);
        foreach ($spec['fields'] as $key => $f) {
            if ($f[1] === 'money') {
                $d[$key] = Tenant::cents($d[$key]);
            }
            if ($f[1] === 'checkbox') {
                $d[$key] = $r->boolean($key);
            }
        }
        if (! empty($d['customer_id'])) {
            $this->t->find('customers', $d['customer_id']);
        }
        if ($resource === 'coupons') {
            $d['code'] = Str::upper($d['code']);
            if ($d['type'] === 'percent' && $d['value'] > 10000) {
                throw ValidationException::withMessages(['value' => 'Percentual máximo: 100%.']);
            }
        }
        if ($resource === 'customers') {
            $phone = trim($d['phone'] ?? '');
            $digits = preg_replace('/\D/', '', $phone);
            if ($digits !== '') {
                if (! str_starts_with($phone, '+') && strlen($digits) <= 11) {
                    $digits = '55'.$digits;
                }
                if (strlen($digits) < 8 || strlen($digits) > 15 || (str_starts_with($digits, '55') && ! in_array(strlen($digits), [12, 13]))) {
                    throw ValidationException::withMessages(['phone' => 'Informe DDI, DDD e telefone completos.']);
                }
                $phone = str_starts_with($digits, '55') ? '+55 ('.substr($digits, 2, 2).') '.substr($digits, 4, -4).'-'.substr($digits, -4) : '+'.$digits;
            }
            $d['phone'] = $d['whatsapp'] = $digits === '' ? null : $phone;
            $d['consented_at'] = now();
        }
        if ($resource === 'products') {
            $addons = [];
            foreach (preg_split('/\R/', trim($d['addons'] ?? '')) as $line) {
                if (trim($line) === '') {
                    continue;
                }$parts = explode('|', $line, 2);
                if (count($parts) !== 2 || mb_strlen(trim($parts[0])) > 80) {
                    throw ValidationException::withMessages(['addons' => 'Use Nome | preço em cada linha.']);
                }
                $addons[] = ['name' => trim($parts[0]), 'price' => Tenant::cents(trim($parts[1]))];
            }
            if (count($addons) > 20) {
                throw ValidationException::withMessages(['addons' => 'Cadastre até 20 adicionais por item.']);
            }
            $d['addons'] = json_encode($addons);
        }
        if (isset($d['image'])) {
            $bytes = collect(Storage::disk('local')->allFiles('companies/'.$this->t->id()))->sum(fn ($f) => Storage::disk('local')->size($f));
            abort_if($bytes + $r->file('image')->getSize() > $this->t->company->plan->storage_mb * 1048576, 422, 'Limite de armazenamento do plano atingido.');
            $d['image'] = $r->file('image')->store('companies/'.$this->t->id(), 'local');
        } else {
            unset($d['image']);
        }
        DB::transaction(function () use ($resource, $id, $d, $before) {
            $this->t->lock();
            if (! $id && in_array($resource, ['customers', 'products'])) {
                $this->t->limit($resource);
            }
            if ($id) {
                $this->t->update($resource, $id, $d);
            } else {
                $id = $this->t->insert($resource, $d);
            }
            if ($resource === 'customers') {
                $this->t->audit('customer.consent', $resource, $id, $before ? ['email' => $before->email_consent, 'whatsapp' => $before->whatsapp_consent] : null, ['email' => $d['email_consent'], 'whatsapp' => $d['whatsapp_consent']]);
            }
            $this->t->audit($before ? 'record.updated' : 'record.created', $resource, $id, $before, $d);
        });

        return redirect('/records/'.$resource)->with('success', 'Cadastro salvo.');
    }

    public function destroy(string $resource, int $id)
    {
        abort_unless(in_array($resource, ['suppliers', 'goals', 'products']), 404);
        $this->spec($resource, true);
        DB::transaction(function () use ($resource, $id) {
            $this->t->lock();
            $record = $this->t->find($resource, $id);
            $this->t->audit('record.deleted', $resource, $id, $record);
            if (in_array($resource, ['suppliers', 'products'])) {
                $this->t->update($resource, $id, ['deleted_at' => now()] + ($resource === 'products' ? ['active' => false] : []));
            } else {
                $this->t->query($resource)->where('id', $id)->delete();
            }
        });

        return back()->with('success', 'Cadastro excluído.');
    }

    public function customer(Request $r, int $id)
    {
        $this->t->authorize('customers');
        $customer = $this->t->find('customers', $id);
        $period = app(Analytics::class)->period($r);
        $query = $this->t->query('sales')->where('customer_id', $id);
        $stats = (clone $query)->where('status', 'completed')->selectRaw('COUNT(*) as count, COALESCE(SUM(total),0) as total, COALESCE(AVG(total),0) as ticket, COALESCE(MAX(total),0) as largest, MIN(created_at) as first, MAX(created_at) as last')->first();
        $sales = (clone $query)->whereBetween('created_at', $period);
        if ($r->filled('method')) {
            if ($r->input('method') === 'fiado') {
                $sales->where('fiado_amount', '>', 0);
            } elseif ($r->input('method') === 'card') {
                $sales->where(fn ($q) => $q->whereJsonContains('payment_methods', 'card')->orWhereJsonContains('payment_methods', 'credit')->orWhereJsonContains('payment_methods', 'debit'));
            } else {
                $sales->whereJsonContains('payment_methods', (string) $r->input('method'));
            }
        }
        if ($r->filled('status')) {
            $sales->where('status', $r->string('status'));
        }
        if ($r->filled('sale')) {
            $sales->where('id', (int) $r->input('sale'));
        }
        if ($r->filled('product')) {
            $sales->whereIn('id', $this->t->query('sale_items')->where('product_id', (int) $r->input('product'))->select('sale_id'));
        }
        $sales = $sales->orderByDesc('id')->paginate(15)->withQueryString();
        $accounts = $this->t->query('accounts')->where('customer_id', $id)->orderBy('due_date')->get();
        $payments = $this->t->query('payments')->where('customer_id', $id)->whereNull('reversed_at')->orderByDesc('id')->get();
        $points = $this->t->query('loyalty_transactions')->where('customer_id', $id)->sum('points');
        $credits = $this->t->query('customer_credits')->where('customer_id', $id)->orderByDesc('id')->get();
        $preferred = $this->t->query('payments')->where('customer_id', $id)->whereNull('reversed_at')->selectRaw('method, COUNT(*) as count')->groupBy('method')->orderByDesc('count')->value('method');
        $usedCoupons = $this->t->query('sales')->join('coupons', 'coupons.id', '=', 'sales.coupon_id')->where('sales.customer_id', $id)->where('sales.status', 'completed')->get(['coupons.code', 'sales.created_at', 'sales.id']);
        $emails = $this->t->query('email_logs')->where('customer_id', $id)->orderByDesc('id')->limit(20)->get();
        $products = $this->t->query('sale_items')->whereIn('sale_id', (clone $query)->where('status', 'completed')->select('id'))->selectRaw('name, SUM(quantity) as quantity')->groupBy('name')->orderByDesc('quantity')->limit(8)->get();
        $timeline = $this->t->query('audit_logs')->where('entity', 'customers')->where('entity_id', $id)->orderByDesc('id')->limit(20)->get();

        $walletEntries = $this->t->query('wallet_entries')->where('customer_id', $id)->orderByDesc('id')->get();

        return view('customer', compact('walletEntries', 'customer', 'stats', 'sales', 'accounts', 'payments', 'points', 'credits', 'preferred', 'usedCoupons', 'emails', 'products', 'timeline'));
    }

    public function privacy(Request $r, int $id)
    {
        $this->t->authorize('customers', true);
        Gate::authorize('manage-company');
        $customer = $this->t->find('customers', $id);
        if ($r->input('action') === 'export') {
            unset($customer->portal_hash);
            $this->t->audit('customer.export', 'customers', $id);

            return response()->streamDownload(function () use ($customer, $id) {
                echo json_encode(['customer' => $customer, 'sales' => $this->t->query('sales')->where('customer_id', $id)->get()->map(function ($s) {
                    unset($s->receipt_hash);

                    return $s;
                }),
                    'payments' => $this->t->query('payments')->where('customer_id', $id)->get(), 'accounts' => $this->t->query('accounts')->where('customer_id', $id)->get()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }, 'cliente-'.$id.'.json', ['Content-Type' => 'application/json']);
        }
        $r->validate(['action' => 'required|in:anonymize', 'confirmation' => 'required|in:ANONIMIZAR']);
        DB::transaction(function () use ($id) {
            $this->t->lock();
            $this->t->update('customers', $id, ['name' => 'Cliente anonimizado #'.$id, 'document' => null, 'phone' => null, 'whatsapp' => null, 'email' => null, 'birthday' => null, 'address' => null, 'district' => null, 'city' => null, 'tags' => null, 'source' => null, 'notes' => null, 'email_consent' => false, 'whatsapp_consent' => false, 'portal_hash' => null, 'portal_expires_at' => null, 'anonymized_at' => now()]);
            $this->t->query('email_logs')->where('customer_id', $id)->update(['recipient' => 'anonimizado', 'body' => 'Removido por anonimização', 'status' => 'cancelled']);
            $this->t->query('sales')->where('customer_id', $id)->update(['notes' => null]);
            $saleIds = $this->t->query('sales')->where('customer_id', $id)->select('id');
            $this->t->query('orders')->whereIn('sale_id', $saleIds)->update(['address' => null]);
            $this->t->query('audit_logs')->where('entity', 'customers')->where('entity_id', $id)->update(['before' => null, 'after' => null]);
            $this->t->audit('customer.anonymized', 'customers', $id);
        });

        return back()->with('success', 'Dados pessoais anonimizados. Registros financeiros preservados.');
    }
}
