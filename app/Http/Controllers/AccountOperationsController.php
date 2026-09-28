<?php

namespace App\Http\Controllers;

use App\Services\CashFlow;
use App\Services\CustomerWallet;
use App\Services\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $r->validate(['month' => 'nullable|date_format:Y-m', 'export' => 'nullable|in:csv']);
        $data = $flow->month($r->input('month', now($this->t->company->timezone)->format('Y-m')));
        if ($r->input('export') === 'csv') {
            return response()->streamDownload(function () use ($data) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Data', 'Origem', 'Descrição', 'Forma', 'Entrada', 'Saída', 'Saldo'], ';', '"', '');
                foreach ($data['entries'] as $e) {
                    fputcsv($out, [Carbon::parse($e['date'])->timezone($this->t->company->timezone)->format('d/m/Y H:i:s'), $e['source'], preg_match('/^[=+@\-]/', $e['description']) ? "'".$e['description'] : $e['description'], config('poseitech.payment_labels.'.$e['method'], $e['method']), number_format(max(0, $e['amount']) / 100, 2, ',', ''), number_format(max(0, -$e['amount']) / 100, 2, ',', ''), number_format($e['balance'] / 100, 2, ',', '')], ';', '"', '');
                }
                fclose($out);
            }, 'fluxo-'.$data['month'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('cash-flow', $data);
    }

    public function flowEntry(Request $r)
    {
        $this->t->authorize('cash', true);
        $d = $r->validate(['amount' => 'required|numeric|min:0.01|max:99999999', 'direction' => 'required|in:in,out', 'method' => 'required|in:'.implode(',', array_keys(config('poseitech.methods'))), 'description' => 'required|string|max:180', 'category' => 'required|string|max:60', 'request_key' => 'required|uuid']);
        DB::transaction(function () use ($d) {
            $this->t->lock();
            if ($this->t->query('flow_entries')->where('request_key', $d['request_key'])->exists()) {
                return;
            }
            $id = $this->t->insert('flow_entries', ['user_id' => auth()->id(), 'amount' => Tenant::cents($d['amount']) * ($d['direction'] === 'out' ? -1 : 1), 'method' => $d['method'], 'description' => $d['description'], 'category' => $d['category'], 'occurred_at' => now(), 'request_key' => $d['request_key']]);
            $this->t->audit('flow.created', 'flow_entries', $id, null, $d);
        }, 3);

        return back()->with('success', 'Movimentação registrada diretamente no fluxo.');
    }
}
