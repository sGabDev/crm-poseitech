@extends('layout')
@section('title','Fluxo de caixa')
@section('content')
<h1>Fluxo de caixa mensal</h1>
<p>Entradas e saídas efetivas da empresa. A abertura de cada caixa não é uma nova receita. Uso de saldo do cliente não gera outra entrada.</p>
<form class="filters"><label>Mês<input type="month" name="month" value="{{ $month }}" required></label><button>Consultar</button><button class="secondary" name="export" value="csv">Exportar CSV</button></form>
<div class="kpi-grid">@foreach(['Saldo anterior'=>$opening,'Entradas'=>$incoming,'Saídas'=>$outgoing,'Saldo final'=>$closing] as $label=>$amount)<div class="kpi"><span>{{ $label }}</span><strong>{{ \App\Services\Tenant::money($amount) }}</strong></div>@endforeach</div>
@if(auth()->user()->allows('cash',true))
<details class="card"><summary>Registrar retirada ou entrada fora do caixa</summary>
<p>Use também para registrar o saldo inicial da empresa. Retiradas afetam o fluxo, sem alterar o caixa operacional aberto.</p>
<form method="post" action="{{ url('/cash-flow') }}" class="form-grid">@csrf<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Tipo<select name="direction"><option value="out">Retirada / despesa</option><option value="in">Entrada / aporte</option></select></label>
<label>Valor<input type="number" name="amount" min="0.01" step="0.01" required></label>
<label>Forma<select name="method">@foreach(config('poseitech.methods') as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
<label>Categoria<input name="category" maxlength="60" placeholder="Aluguel, fornecedor, retirada, aporte..." required></label>
<label>Descrição<input name="description" maxlength="180" required></label><button>Registrar movimentação</button></form></details>
@endif
<section class="card"><h2>Resumo por caixa e origem</h2><div class="table-wrap"><table><thead><tr><th>Origem</th><th>Forma</th><th>Entradas</th><th>Saídas</th><th>Líquido</th></tr></thead><tbody>
@forelse($sources as $source=>$methods) @foreach($methods as $method=>$amounts)<tr><td>{{ $source }}</td><td>{{ config('poseitech.payment_labels.'.$method,$method) }}</td><td>{{ \App\Services\Tenant::money($amounts['in']) }}</td><td>{{ \App\Services\Tenant::money($amounts['out']) }}</td><td>{{ \App\Services\Tenant::money($amounts['in']-$amounts['out']) }}</td></tr>@endforeach
@empty<tr><td colspan="5">Sem movimentações neste mês.</td></tr>@endforelse
</tbody></table></div></section>
<section class="card"><h2>Extrato do mês</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Origem</th><th>Descrição</th><th>Forma</th><th>Entrada</th><th>Saída</th><th>Saldo</th></tr></thead><tbody>
@foreach($entries as $e)<tr><td>{{ \Carbon\Carbon::parse($e['date'])->timezone($company->timezone)->format('d/m/Y H:i:s') }}</td><td>{{ $e['source'] }}</td><td>{{ $e['description'] }}</td><td>{{ config('poseitech.payment_labels.'.$e['method'],$e['method']) }}</td><td>{{ $e['amount']>0?\App\Services\Tenant::money($e['amount']):'—' }}</td><td>{{ $e['amount']<0?\App\Services\Tenant::money(-$e['amount']):'—' }}</td><td>{{ \App\Services\Tenant::money($e['balance']) }}</td></tr>@endforeach
</tbody></table></div></section>
@endsection
