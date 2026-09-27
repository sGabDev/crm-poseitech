@extends('layout')
@section('title',$credit ? 'Fiados' : 'Financeiro')
@section('content')<div class="page-heading">
<div>
<h1>{{ $credit ? 'Valores pendentes' : 'Financeiro' }}</h1>
<p>{{ $credit ? 'Acompanhe vencimentos e registre recebimentos parciais ou totais.' : 'Contas, recebimentos e previsibilidade para o seu negócio.' }}</p>
</div>
<button class="secondary" data-print>Imprimir / PDF</button>
</div>
<div class="kpi-grid">
<div class="kpi featured">
<span>A receber</span>
<strong>{{ \App\Services\Tenant::money($totals['receivable'] ?? 0) }}</strong>
</div>@if(!$credit)<div class="kpi">
<span>A pagar</span>
<strong>{{ \App\Services\Tenant::money($totals['payable'] ?? 0) }}</strong>
</div>
<div class="kpi">
<span>Recebimentos menos pagamentos</span>
<strong>{{ \App\Services\Tenant::money($balance) }}</strong>
</div>
<div class="kpi">
<span>Saldo projetado</span>
<strong>{{ \App\Services\Tenant::money($balance+($totals['receivable'] ?? 0)-($totals['payable'] ?? 0)) }}</strong>
</div>@endif</div>
<form class="filters">
<label>Tipo<select name="type">
<option value="">Todos</option>
<option value="receivable" @selected(request('type')==='receivable')>A receber</option>
<option value="payable" @selected(request('type')==='payable')>A pagar</option>
</select>
</label>
<label>Situação<select name="status">@foreach([''=>'Todas','pending'=>'Pendentes','paid'=>'Pagas','overdue'=>'Vencidas','cancelled'=>'Canceladas'] as $k=>$v)<option value="{{ $k }}" @selected(request('status')===$k)>{{ $v }}</option>@endforeach</select>
</label>
<button class="secondary">Filtrar</button>
</form>
<section class="card">@include('components.accounts'){{ $accounts->links() }}</section>
@if(!$credit && auth()->user()->allows('finance',true))<details class="card">
<summary>+ Cadastrar conta a pagar / receber</summary>
<form method="post" action="{{ url('/accounts') }}" class="form-grid">@csrf<label>Tipo<select name="type">
<option value="payable">A pagar</option>
<option value="receivable">A receber</option>
</select>
</label>
<label>Descrição<input name="description" required>
</label>
<label>Categoria<input name="category" placeholder="Ex.: aluguel, materiais, serviços">
</label>
<label>Valor ({{ $company->currency ?? 'BRL' }})<input name="amount" type="number" step="0.01" min="0.01" required>
</label>
<label>Vencimento<input type="date" name="due_date" required>
</label>
<label>Recorrência<select name="recurrence">
<option value="none">Não repetir</option>
<option value="weekly">Semanal</option>
<option value="monthly">Mensal</option>
</select>
</label>
<label>Cliente<select name="customer_id">
<option value="">Nenhum</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
</label>
<label>Fornecedor<select name="supplier_id">
<option value="">Nenhum</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
</label>
<label class="full">Observação<textarea name="notes">
</textarea>
</label>
<button>Cadastrar conta</button>
</form>
</details>@endif
@include('components.billing-actions')
@endsection
