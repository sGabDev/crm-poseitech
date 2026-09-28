<?php

namespace App\Http\Controllers;

use App\Services\CashFlow;
use App\Services\CustomerWallet;
use App\Services\FlowCategories;
use App\Services\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountOperationsController extends Controller
{
    public function __construct(private Tenant $t) {}

    public function deposit(Request $r, CustomerWallet $wallet)
    {
        $d = $r->validate(['customer_id' => 'required|integer', 'amount' => 'required|numeric|min:0.01|max:99999999', 'method' => 'required|in:'.implode(',', array_keys(config('poseitech.methods'))), 'request_key' => 'required|uuid']);
        $wallet->deposit($d);

        return redirect('/customers/'.$d['customer_id'])->with('success', 'Depósito registrado. O fiado foi abatido e o excedente ficou como saldo na conta.');
    }

    public function flow(Request $r, CashFlow $flow)
    {
        $this->t->authorize('cash');
        $categories = app(FlowCategories::class);
        $r->validate(['month' => 'nullable|date_format:Y-m', 'export' => 'nullable|in:csv', 'category' => ['nullable', Rule::in($categories->filters())]]);
        $data = $flow->month($r->input('month', now($this->t->company->timezone)->format('Y-m')), $r->input('category'));
        $data['categories'] = $categories->choices();
        $data['incomingCategories'] = $categories->choices('in');
        $data['outgoingCategories'] = $categories->choices('out');
        $data['filterCategories'] = $categories->filters();
        if ($r->input('export') === 'csv') {
            return response()->streamDownload(function () use ($data) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Data', 'Modo', 'Descrição', 'Entrada', 'Saída', 'Diferença'], ';', '"', '');
                foreach ($data['groups'] as $group) {
                    fputcsv($out, [Carbon::parse($group['date'])->format('d/m/Y'), config('poseitech.payment_labels.'.$group['method'], $group['method']), $group['count'].' movimentações', number_format($group['incoming'] / 100, 2, ',', ''), number_format($group['outgoing'] / 100, 2, ',', ''), number_format($group['difference'] / 100, 2, ',', '')], ';', '"', '');
                }
                fclose($out);
            }, 'fluxo-'.$data['month'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('cash-flow', $data);
    }

    public function flowEntry(Request $r)
    {
        $this->t->authorize('cash', true);
        $d = $r->validate(['amount' => 'required|numeric|min:0.01|max:99999999', 'direction' => 'required|in:in,out', 'method' => 'required|in:'.implode(',', array_keys(config('poseitech.methods'))), 'description' => 'required|string|max:180', 'category' => ['required', 'string', Rule::in(app(FlowCategories::class)->choices($r->input('direction') === 'in' ? 'in' : 'out'))], 'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.now($this->t->company->timezone)->toDateString()], 'request_key' => 'required|uuid']);
        $d['occurred_at'] = ! empty($d['date']) ? Carbon::createFromFormat('!Y-m-d', $d['date'], $this->t->company->timezone)->utc() : now();
        DB::transaction(function () use ($d) {
            $this->t->lock();
            if ($this->t->query('flow_entries')->where('request_key', $d['request_key'])->exists()) {
                return;
            }
            $id = $this->t->insert('flow_entries', ['user_id' => auth()->id(), 'amount' => Tenant::cents($d['amount']) * ($d['direction'] === 'out' ? -1 : 1), 'method' => $d['method'], 'description' => $d['description'], 'category' => $d['category'], 'occurred_at' => $d['occurred_at'], 'request_key' => $d['request_key']]);
            $this->t->audit('flow.created', 'flow_entries', $id, null, $d);
        }, 3);

        return redirect('/cash-flow?month='.Carbon::parse($d['occurred_at'])->timezone($this->t->company->timezone)->format('Y-m'))->with('success', 'Movimentação registrada na data informada.');
    }

    public function categories(Request $r)
    {
        Gate::authorize('manage-company');
        $this->t->authorize('cash', true);
        $d = $r->validate(['categories_in' => 'required|string|max:4000', 'categories_out' => 'required|string|max:4000']);
        $settings = [];
        foreach (['in', 'out'] as $direction) {
            $names = collect(preg_split('/\R/u', $d['categories_'.$direction]))->map(fn ($name) => trim($name))->filter()->unique()->values();
            if ($names->isEmpty() || $names->count() > 50 || $names->contains(fn ($name) => mb_strlen($name) > 60)) {
                throw ValidationException::withMessages(['categories_'.$direction => 'Informe de 1 a 50 categorias, com até 60 caracteres cada.']);
            }
            $settings['flow_categories_'.$direction] = $names->all();
        }
        DB::transaction(function () use ($settings) {
            $this->t->lock();
            $company = $this->t->company->fresh();
            $company->update(['settings' => array_merge($company->settings ?? [], $settings)]);
            $this->t->audit('flow.categories_updated', 'companies', $company->id);
        });

        return back()->with('success', 'Categorias atualizadas. Categorias antigas continuam disponíveis no filtro do histórico.');
    }
}
