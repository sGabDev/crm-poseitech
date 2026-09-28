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
@include('components.flow-statement')
@endsection
