@extends('layout')
@section('title','Fluxo de caixa')
@section('content')
<h1>Fluxo de caixa mensal</h1>
<p>Entradas e saídas efetivas da empresa. A abertura de cada caixa não é uma nova receita. Uso de saldo do cliente não gera outra entrada.</p>
<form class="filters"><label>Mês<input type="month" name="month" value="{{ $month }}" required></label><label>Categoria<select name="category"><option value="">Todas</option>@foreach($filterCategories as $category)<option value="{{ $category }}" @selected(request('category')===$category)>{{ $category }}</option>@endforeach</select></label><button>Consultar</button><button class="secondary" name="export" value="csv">Exportar CSV</button></form>
@if(request('category'))<p class="notice">Totais e saldos abaixo consideram somente a categoria {{ request('category') }}.</p>@endif
<div class="kpi-grid">@foreach(['Saldo anterior'=>$opening,'Entradas'=>$incoming,'Saídas'=>$outgoing,'Saldo final'=>$closing] as $label=>$amount)<div class="kpi"><span>{{ $label }}</span><strong>{{ \App\Services\Tenant::money($amount) }}</strong></div>@endforeach</div>
<div class="kpi-grid">@foreach($methodTotals as $method=>$amounts)<div class="kpi"><h2>{{ config('poseitech.payment_labels.'.$method,$method) }}</h2><span>Recebido</span><strong class="flow-in">{{ \App\Services\Tenant::money($amounts['in']) }}</strong><span>Gasto</span><strong class="flow-out">{{ \App\Services\Tenant::money($amounts['out']) }}</strong></div>@endforeach</div>
@if(auth()->user()->allows('cash',true))
<details class="card"><summary>Registrar retirada ou entrada fora do caixa</summary>
<p>Use também para registrar o saldo inicial da empresa. Retiradas afetam o fluxo, sem alterar o caixa operacional aberto.</p>
<form method="post" action="{{ url('/cash-flow') }}" class="form-grid">@csrf<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Data do lançamento<input type="date" name="date" value="{{ old('date',now($company->timezone)->toDateString()) }}" max="{{ now($company->timezone)->toDateString() }}" min="1000-01-01" required></label>
<label>Tipo<select name="direction"><option value="out">Retirada / despesa</option><option value="in">Entrada / aporte</option></select></label>
<label>Valor<input type="number" name="amount" min="0.01" step="0.01" required></label>
<label>Forma<select name="method">@foreach(config('poseitech.methods') as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
<label>Categoria<select name="category" required>@foreach($categories as $category)<option value="{{ $category }}" @selected(old('category')===$category)>{{ $category }}</option>@endforeach</select></label>
<label>Descrição<input name="description" maxlength="180" required></label><button>Registrar movimentação</button></form></details>
@endif
@can('manage-company')
<details class="card"><summary>Configurar categorias da empresa</summary><p>Uma categoria por linha. As categorias retiradas da lista continuam no histórico e nos filtros dos lançamentos existentes.</p><form method="post" action="{{ url('/cash-flow/categories') }}" class="stack">@csrf<label>Categorias<textarea name="categories" rows="6" required>{{ old('categories',implode("\n",$categories)) }}</textarea></label><button>Salvar categorias</button></form></details>
@endcan
@include('components.flow-statement')
@endsection
