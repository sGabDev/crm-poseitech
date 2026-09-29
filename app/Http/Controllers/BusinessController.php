<?php

namespace App\Http\Controllers;

use App\Services\Analytics;
use App\Services\Commerce;
use App\Services\ReportExport;
use App\Services\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    public function __construct(private Tenant $t, private Commerce $commerce, private Analytics $analytics) {}

    public function dashboard(Request $r)
    {
        if (! $this->t->company->enabled('sales') || ! auth()->user()->allows('sales')) {
            foreach (['customers' => '/records/customers', 'products' => '/records/products', 'cash' => '/cash', 'finance' => '/records/suppliers', 'credit' => '/credit', 'stock' => '/stock', 'orders' => '/orders', 'campaigns' => '/campaigns', 'loyalty' => '/records/coupons'] as $module => $path) {
                if ($this->t->company->enabled($module) && auth()->user()->allows($module)) {
                    return redirect($path);
                }
            }
            if (in_array(auth()->user()->role, ['admin', 'super'])) {
                return redirect('/settings');
            }

            return response()->view('no-access', [], 403);
        }
        $this->t->authorize('sales');

        return view('dashboard', $this->analytics->dashboard($this->analytics->period($r)));
    }

    public function opportunities()
    {
        $this->t->authorize('customers');

        return view('opportunities', ['cards' => $this->analytics->opportunities()]);
    }

    public function sales(Request $r)
    {
        $this->t->authorize('sales');
        $q = $this->t->query('sales')->whereBetween('created_at', $this->analytics->period($r));
        if ($r->filled('q')) {
            $q->where('id', (int) $r->input('q'));
        }

        return view('sales', ['sales' => $q->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function saleForm()
    {
        $this->t->authorize('sales', true);

        return view('sale-form', ['registerOpen' => $this->t->query('cash_registers')->whereNull('closed_at')->exists(), 'wallets' => $this->t->query('wallet_entries')->selectRaw('customer_id, SUM(amount) as balance')->groupBy('customer_id')->pluck('balance', 'customer_id'), 'debts' => $this->t->query('accounts')->where('origin', 'credit')->where('status', 'pending')->selectRaw('customer_id, SUM(amount-paid) as balance')->groupBy('customer_id')->pluck('balance', 'customer_id'), 'customers' => $this->t->query('customers')->whereNull('anonymized_at')->orderBy('name')->get(), 'products' => $this->t->query('products')->where('active', true)->orderBy('name')->get()]);
    }

    public function sell(Request $r)
    {
        $d = $r->validate(['catalog_order_id' => 'nullable|integer', 'request_key' => 'required|uuid', 'customer_id' => 'nullable|integer', 'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'nullable|integer', 'items.*.name' => 'nullable|string|max:160', 'items.*.price' => 'nullable|numeric|min:0.01|max:9999999', 'allow_negative_stock' => 'nullable|boolean',
            'items.*.quantity' => 'required|integer|min:1|max:10000', 'items.*.addons' => 'nullable|array|max:20', 'items.*.addons.*' => 'integer|min:0|max:19', 'discount' => 'nullable|string|max:30', 'extra' => 'nullable|string|max:30', 'use_balance' => 'nullable|boolean',
            'payments' => 'required|array|min:1|max:8', 'payments.*.method' => 'required|in:'.implode(',', array_keys(config('poseitech.sale_methods'))),
            'payments.*.amount' => 'required|numeric|min:0', 'auto_payment' => 'nullable|boolean',
            'notes' => 'nullable|string|max:3000', 'coupon' => 'nullable|string|max:40', 'order' => 'nullable|boolean', 'delivery' => 'nullable|boolean',
            'address' => 'nullable|string|max:255', 'region' => 'nullable|string|max:100', 'fee' => 'nullable|numeric|min:0']);

        return redirect('/sales/'.$this->commerce->sell($d))->with('success', 'Venda registrada.');
    }

    public function sale(int $id)
    {
        $this->t->authorize('sales');
        $sale = $this->t->find('sales', $id);

        return view('sale', ['sale' => $sale, 'items' => $this->t->query('sale_items')->where('sale_id', $id)->get(),
            'payments' => $this->t->query('payments')->where('sale_id', $id)->get(), 'accounts' => $this->t->query('accounts')->where('sale_id', $id)->get(),
            'customer' => $sale->customer_id ? $this->t->find('customers', $sale->customer_id) : null]);
    }

    public function cancel(Request $r, int $id)
    {
        $r->validate(['reason' => 'required|string|min:5|max:500']);
        $this->commerce->cancel($id, $r->input('reason'));

        return back()->with('success', 'Venda cancelada e lançamentos estornados.');
    }

    public function cash()
    {
        $this->t->authorize('cash');
        $register = $this->t->query('cash_registers')->whereNull('closed_at')->first();

        return view('cash', ['register' => $register, 'expected' => $register ? $this->commerce->expected($register) : 0,
            'transactions' => $register ? $this->t->query('cash_transactions')->where('cash_register_id', $register->id)->orderByDesc('id')->get() : collect(),
            'history' => $this->t->query('cash_registers')->orderByDesc('id')->paginate(15)]);
    }

    public function cashAction(Request $r)
    {
        $this->t->authorize('cash', true);
        $d = $r->validate(['action' => 'required|in:open,close,in,out', 'amount' => 'required|numeric|min:0|max:99999999', 'description' => 'nullable|string|max:255']);
        DB::transaction(function () use ($d) {
            $this->t->lock();
            $register = $this->t->query('cash_registers')->whereNull('closed_at')->first();
            $amount = Tenant::cents($d['amount']);
            if ($d['action'] === 'open') {
                if ($register) {
                    throw ValidationException::withMessages(['cash' => 'Já existe um caixa aberto.']);
                }
                $id = $this->t->insert('cash_registers', ['user_id' => auth()->id(), 'opening' => $amount]);
                $this->t->audit('cash.opened', 'cash_registers', $id);
            } else {
                abort_unless($register, 422, 'Abra um caixa.');
                $expected = $this->commerce->expected($register);
                if ($d['action'] === 'close') {
                    $this->t->update('cash_registers', $register->id, ['expected' => $expected, 'counted' => $amount, 'difference' => $amount - $expected, 'closed_at' => now()]);
                    $this->t->audit('cash.closed', 'cash_registers', $register->id, null, ['expected' => $expected, 'counted' => $amount]);
                    if ($amount !== $expected) {
                        $this->t->insert('alerts', ['key' => 'cash-'.$register->id, 'title' => 'Divergência no caixa', 'body' => 'Diferença de '.Tenant::money($amount - $expected), 'url' => '/cash']);
                    }
                } else {
                    if ($d['action'] === 'out' && $amount > $expected) {
                        throw ValidationException::withMessages(['amount' => 'Saldo insuficiente.']);
                    }
                    if (! $amount || empty($d['description'])) {
                        throw ValidationException::withMessages(['description' => 'Informe o motivo e um valor maior que zero.']);
                    }
                    $this->t->insert('cash_transactions', ['cash_register_id' => $register->id, 'user_id' => auth()->id(), 'description' => $d['description'], 'method' => 'cash', 'amount' => $d['action'] === 'in' ? $amount : -$amount]);
                    $this->t->audit('cash.'.$d['action'], 'cash_registers', $register->id, null, $d);
                }
            }
        }, 3);

        return back()->with('success', 'Caixa atualizado.');
    }

    public function finance(Request $r)
    {
        $credit = $r->is('credit');
        $this->t->authorize($credit ? 'credit' : 'finance');
        $q = $this->t->query('accounts');
        if ($credit) {
            $q->where('origin', 'credit');
        }
        if ($r->filled('type')) {
            $q->where('type', $r->input('type'));
        }
        if ($r->input('status') === 'overdue') {
            $q->where('status', 'pending')->where('due_date', '<', now()->toDateString());
        } elseif ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }
        $totals = (clone $q)->where('status', 'pending')->selectRaw('type, SUM(amount-paid) as total')->groupBy('type')->pluck('total', 'type');
        $balance = $this->t->query('payments')->whereNull('reversed_at')->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0) as balance")->value('balance');

        return view('finance', ['credit' => $credit, 'accounts' => $q->orderBy('due_date')->paginate(20)->withQueryString(), 'totals' => $totals, 'balance' => $balance,
            'customers' => $this->t->query('customers')->whereNull('anonymized_at')->orderBy('name')->limit(1000)->get(), 'suppliers' => $this->t->query('suppliers')->orderBy('name')->get()]);
    }

    public function debtors(Request $r)
    {
        $this->t->authorize('credit');
        $r->validate(['sort' => 'nullable|in:balance,recent,oldest,name', 'q' => 'nullable|string|max:100']);
        $balances = $this->t->query('accounts')->where('origin', 'credit')->where('status', 'pending')->selectRaw('customer_id, SUM(amount-paid) as debt, MAX(created_at) as last_credit, MIN(due_date) as next_due')->groupBy('customer_id')->havingRaw('SUM(amount-paid)>0');
        $q = $this->t->query('customers')->joinSub($balances, 'balances', fn ($join) => $join->on('customers.id', '=', 'balances.customer_id'))->select('customers.*', 'balances.debt', 'balances.last_credit', 'balances.next_due');
        if ($r->filled('q')) {
            $q->where('customers.name', 'like', '%'.$r->input('q').'%');
        }
        $total = (clone $q)->sum('balances.debt');
        match ($r->input('sort', 'balance')) {
            'recent' => $q->orderByDesc('balances.last_credit'),'oldest' => $q->orderBy('balances.next_due'),'name' => $q->orderBy('customers.name'),default => $q->orderByDesc('balances.debt')
        };

        return view('debtors', ['customers' => $q->paginate(20)->withQueryString(), 'total' => $total]);
    }

    public function receiveDebt(Request $r, int $id)
    {
        $d = $r->validate(['catalog_order_id' => 'nullable|integer', 'request_key' => 'required|uuid', 'amount' => 'required|numeric|min:0.01', 'method' => 'required|in:'.implode(',', array_keys(config('poseitech.methods')))]);
        $this->commerce->settleCustomer($id, $d);

        return back()->with('success', 'Pagamento abatido do saldo devedor do cliente.');
    }

    public function account(Request $r)
    {
        $this->t->authorize('finance', true);
        $d = $r->validate(['type' => 'required|in:receivable,payable', 'description' => 'required|string|max:255', 'category' => 'nullable|string|max:100',
            'customer_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer', 'amount' => 'required|numeric|min:0.01|max:99999999', 'due_date' => 'required|date',
            'recurrence' => 'required|in:none,weekly,monthly', 'notes' => 'nullable|string|max:3000']);
        if (! empty($d['customer_id'])) {
            $this->t->find('customers', $d['customer_id']);
        }
        if (! empty($d['supplier_id'])) {
            $this->t->find('suppliers', $d['supplier_id']);
        }
        $d['amount'] = Tenant::cents($d['amount']);
        DB::transaction(function () use ($d) {
            $id = $this->t->insert('accounts', $d);
            $this->t->audit('account.created', 'accounts', $id, null, $d);
        });

        return back()->with('success', 'Conta cadastrada.');
    }

    public function settle(Request $r, int $id)
    {
        $d = $r->validate(['request_key' => 'nullable|uuid', 'amount' => 'required|numeric|min:0.01', 'method' => 'required|in:'.implode(',', array_keys(config('poseitech.methods')))]);
        $this->commerce->settle($id, $d);

        return back()->with('success', 'Pagamento registrado.');
    }

    public function stock()
    {
        $this->t->authorize('stock');

        return view('stock', ['products' => $this->t->query('products')->where('type', 'product')->orderBy('name')->get(), 'movements' => $this->t->query('stock_movements')->orderByDesc('id')->paginate(20)]);
    }

    public function stockAction(Request $r)
    {
        $this->t->authorize('stock', true);
        $d = $r->validate(['product_id' => 'required|integer', 'type' => 'required|in:in,out,adjustment,loss,return', 'quantity' => 'required|integer|min:0|max:1000000', 'notes' => 'required|string|max:255']);
        if ($d['type'] !== 'adjustment' && ! $d['quantity']) {
            throw ValidationException::withMessages(['quantity' => 'Informe uma quantidade maior que zero.']);
        }
        DB::transaction(function () use ($d) {
            $this->t->lock();
            $p = $this->t->find('products', $d['product_id']);
            $delta = match ($d['type']) {
                'out','loss' => -$d['quantity'],'adjustment' => $d['quantity'] - $p->stock,default => $d['quantity']
            };
            $this->commerce->stock($p->id, $delta, $d['type'], $d['notes']);
            $this->t->audit('stock.moved', 'products', $p->id, ['stock' => $p->stock], $d);
        }, 3);

        return back()->with('success', 'Estoque atualizado.');
    }

    public function orders()
    {
        $this->t->authorize('orders');
        $orders = $this->t->query('orders')->leftJoin('sales', 'sales.id', '=', 'orders.sale_id')->leftJoin('customers', function ($join) {
            $join->on('customers.id', '=', 'sales.customer_id')->on('customers.company_id', '=', 'orders.company_id');
        })->select('orders.*', 'customers.address as customer_address', 'customers.name as customer_name')->whereNotIn('orders.status', ['delivered', 'cancelled'])->orderBy('orders.id')->get();

        return view('orders', ['onlineOrders' => $this->t->query('catalog_orders')->orderByDesc('id')->paginate(15, ['*'], 'online_page'), 'orders' => $orders, 'history' => $this->t->query('orders')->whereIn('status', ['delivered', 'cancelled'])->orderByDesc('id')->paginate(15),
            'drivers' => DB::table('users')->where('company_id', $this->t->id())->where('active', true)->get(['id', 'name'])]);
    }

    public function orderAction(Request $r, int $id)
    {
        $this->t->authorize('orders', true);
        $d = $r->validate(['status' => 'required|in:confirmed,preparing,ready,shipping,delivered', 'driver_id' => 'nullable|integer', 'estimated_at' => 'nullable|date']);
        DB::transaction(function () use ($d, $id) {
            $this->t->lock();
            $order = $this->t->find('orders', $id);
            $next = ['received' => ['confirmed'], 'confirmed' => ['preparing'], 'preparing' => ['ready'], 'ready' => $order->delivery ? ['shipping'] : ['delivered'], 'shipping' => ['delivered']];
            abort_unless(in_array($d['status'], $next[$order->status] ?? []), 422, 'Transição de status inválida.');
            if ($order->delivery) {
                $this->t->authorize('delivery', true);
            }
            if (! empty($d['driver_id'])) {
                abort_unless(DB::table('users')->where('company_id', $this->t->id())->where('active', true)->where('id', $d['driver_id'])->exists(), 422, 'Entregador inválido.');
            }
            $this->t->update('orders', $id, $d);
            $this->t->audit('order.updated', 'orders', $id, $order, $d);
        });

        return back()->with('success', 'Pedido atualizado.');
    }

    public function reports(Request $r)
    {
        $period = $this->analytics->period($r);
        if ($r->input('export') === 'csv') {
            return app(ReportExport::class)->download($r->input('report', 'sales'), $period);
        }
        $this->t->authorize('finance');
        $data = $this->analytics->dashboard($period);
        $sales = $this->analytics->sales($period);
        $data['sellers'] = (clone $sales)->join('users', 'users.id', '=', 'sales.user_id')->selectRaw('users.name, COUNT(*) as count, SUM(sales.total) as total, AVG(sales.total) as ticket')->groupBy('users.id', 'users.name')->get();
        $data['categories'] = $this->t->query('sale_items')->leftJoin('products', 'products.id', '=', 'sale_items.product_id')->whereIn('sale_id', (clone $sales)->select('sales.id'))->selectRaw('products.category, SUM(sale_items.total) as total')->groupBy('products.category')->get();
        $data['forecast'] = $this->t->query('sales')->where('status', 'completed')->where('created_at', '>=', now()->subDays(30))->where('created_at', '<', now()->startOfDay())->sum('total') / 30;
        $data['campaignResults'] = $this->t->query('email_logs')->selectRaw('status, COUNT(*) as count')->groupBy('status')->get();
        $data['loyaltyBalance'] = $this->t->query('loyalty_transactions')->sum('points');
        $data['expenses'] = $this->t->query('accounts')->where('type', 'payable')->where('status', '!=', 'cancelled')->whereBetween('due_date', [$period[0]->toDateString(), $period[1]->toDateString()])->sum('amount');
        $data['forecastDetails'] = $this->analytics->forecast();
        $group = $r->input('group', 'day');
        abort_unless(in_array($group, ['day', 'week', 'month']), 422);
        $data['flow'] = [];
        foreach ($this->t->query('payments')->whereNull('reversed_at')->whereBetween('created_at', $period)->orderBy('created_at')->cursor() as $p) {
            $date = Carbon::parse($p->created_at)->timezone($this->t->company->timezone);
            $key = match ($group) {
                'week' => $date->startOfWeek()->format('d/m/Y'),'month' => $date->format('m/Y'),default => $date->format('d/m/Y')
            };
            $data['flow'][$key] ??= ['in' => 0, 'out' => 0];
            $data['flow'][$key][$p->direction] += $p->amount;
        }

        return view('reports', $data);
    }

    public function search(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);
        $term = $r->input('q', '');
        $results = [];
        if (mb_strlen($term) >= 2) {
            foreach (['customers' => ['name', 'phone'], 'products' => ['name', 'code'], 'sales' => ['id'], 'orders' => ['id'], 'accounts' => ['description', 'id']] as $table => $fields) {
                $module = $table === 'accounts' ? 'finance' : $table;
                if (! $this->t->company->enabled($module) || ! auth()->user()->allows($module)) {
                    continue;
                }
                $q = $this->t->query($table)->where(function ($query) use ($fields, $term) {
                    foreach ($fields as $field) {
                        $query->orWhere($field, 'like', '%'.$term.'%');
                    }
                });
                $results[$table] = $q->limit(12)->get();
            }
        }

        return view('search', compact('results', 'term'));
    }

    public function alerts()
    {
        Gate::authorize('manage-company');

        return view('alerts', ['alerts' => $this->t->query('alerts')->orderByDesc('id')->paginate(20)]);
    }

    public function readAlert(int $id)
    {
        Gate::authorize('manage-company');
        $this->t->find('alerts', $id);
        $this->t->update('alerts', $id, ['read_at' => now()]);

        return back();
    }

    public function redeem(Request $r, int $id)
    {
        $this->t->authorize('loyalty', true);
        $d = $r->validate(['points' => 'required|integer|min:1', 'description' => 'required|string|max:255']);
        DB::transaction(function () use ($d, $id) {
            $this->t->lock();
            $this->t->find('customers', $id);
            $balance = $this->t->query('loyalty_transactions')->where('customer_id', $id)->sum('points');
            abort_if($d['points'] > $balance, 422, 'Saldo de pontos insuficiente.');
            $this->t->insert('loyalty_transactions', ['customer_id' => $id, 'points' => -$d['points'], 'description' => $d['description']]);
            $this->t->audit('loyalty.redeemed', 'customers', $id, null, $d);
        });

        return back()->with('success', 'Benefício registrado.');
    }

    public function creditAdjustment(Request $r, int $id)
    {
        $this->t->authorize('loyalty', true);
        Gate::authorize('manage-company');
        $d = $r->validate(['amount' => 'required|numeric|min:0.01|max:99999999', 'direction' => 'required|in:in,out', 'description' => 'required|string|max:255']);
        DB::transaction(function () use ($d, $id) {
            $this->t->lock();
            $this->t->find('customers', $id);
            $amount = Tenant::cents($d['amount']);
            $balance = $this->t->query('customer_credits')->where('customer_id', $id)->sum('amount');
            abort_if($d['direction'] === 'out' && $amount > $balance, 422, 'Crédito insuficiente.');
            $this->t->insert('customer_credits', ['customer_id' => $id, 'user_id' => auth()->id(), 'amount' => $d['direction'] === 'in' ? $amount : -$amount, 'description' => $d['description']]);
            $this->t->audit('customer.credit', 'customers', $id, null, $d);
        });

        return back()->with('success', 'Crédito atualizado.');
    }
}
