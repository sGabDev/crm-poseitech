@extends('layout',['public'=>true])
@section('title','Portal do cliente')
@section('content')<div class="page-heading">
<div>
<div class="eyebrow">{{ $company->name }}</div>
<h1>Olá, {{ $customer->name }}</h1>
<p>Suas compras e pagamentos, com transparência.</p>
</div>
<form method="post" action="{{ url('/portal/logout') }}">@csrf<button class="secondary">Sair do portal</button>
</form>
</div>@include('components.period',['paymentFilter'=>true])<div class="kpi-grid">
<div class="kpi">
<span>Comprado no período</span>
<strong>{{ \App\Services\Tenant::money($totals->total) }}</strong>
</div>
<div class="kpi">
<span>Pago nas compras do período</span>
<strong>{{ \App\Services\Tenant::money($totals->paid) }}</strong>
</div>
<div class="kpi">
<span>Pendente total</span>
<strong>{{ \App\Services\Tenant::money($accounts->where('status','pending')->sum(fn($a)=>$a->amount-$a->paid)) }}</strong>
</div>
<div class="kpi">
<span>Fidelidade</span>
<strong>{{ $points }}</strong>
<small>Pontos / benefícios</small>
</div>
</div>
@forelse($sales as $sale)<section class="card">
<div class="section-heading">
<div>
<h2>Compra #{{ $sale->id }} · {{ $company->name }}</h2>
<small>{{ \Carbon\Carbon::parse($sale->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }} · {{ $sale->status==='cancelled' ? 'Cancelada' : ($sale->paid===$sale->total ? 'Paga' : 'Pagamento pendente') }}</small>
</div>
<a target="_blank" href="{{ url('/receipt/'.$sale->receipt_hash) }}">Comprovante ↗</a>
</div>@include('components.items',['items'=>$items[$sale->id] ?? collect()])<div class="metric-row">
<span>Desconto {{ \App\Services\Tenant::money($sale->discount) }} · Acréscimo {{ \App\Services\Tenant::money($sale->extra) }}</span>
<strong>Total {{ \App\Services\Tenant::money($sale->total) }}</strong>
</div>
</section>@empty<div class="card empty">Nenhuma compra neste período.</div>@endforelse{{ $sales->links() }}<section class="card">
<h2>Valores pendentes</h2>@include('components.accounts',['readonly'=>true])</section>
<section class="card">
<div class="section-heading">
<h2>Extrato da conta</h2>
<button data-print class="secondary">Imprimir / PDF</button>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Data</th>
<th>Movimento</th>
<th>Valor</th>
<th>Saldo pendente</th>
</tr>
</thead>
<tbody>@php($balance=0)@foreach($ledger as $entry)@php($balance+=$entry->amount)<tr>
<td>{{ \Carbon\Carbon::parse($entry->date)->timezone($company->timezone)->format('d/m/Y H:i') }}</td>
<td>{{ $entry->label }}</td>
<td>{{ \App\Services\Tenant::money($entry->amount) }}</td>
<td>{{ \App\Services\Tenant::money($balance) }}</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>@include('components.wallet')
@include('components.credits',['readonly'=>true])
@endsection
