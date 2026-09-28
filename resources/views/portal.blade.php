@extends('layout',['public'=>true])
@section('title','Portal do cliente')
@section('content')
<div class="page-heading"><div><div class="eyebrow">{{ $company->name }}</div><h1>Olá, {{ $customer->name }}</h1><p>Compras referentes a {{ $monthLabel }}.</p></div>
<form method="post" action="{{ url('/portal/logout') }}">@csrf<button class="secondary">Sair do portal</button></form></div>
<form class="filters"><label>Mês de referência<input type="month" name="month" value="{{ $month }}" required></label><button>Consultar mês</button></form>
<div class="kpi-grid"><div class="kpi"><span>Comprado em {{ $monthLabel }}</span><strong>{{ \App\Services\Tenant::money($totals->total) }}</strong></div><div class="kpi"><span>Pago nas compras do mês</span><strong>{{ \App\Services\Tenant::money($totals->paid) }}</strong></div>
@if($accounts->isNotEmpty())<div class="kpi"><span>Valores em aberto (todos os meses)</span><strong>{{ \App\Services\Tenant::money($accounts->sum(fn($a)=>$a->amount-$a->paid)) }}</strong></div>@endif</div>
<section class="card"><h2>Compras de {{ $monthLabel }}</h2><div class="table-wrap"><table><thead><tr><th>Número da compra</th><th>Valor</th><th>Comprovante</th></tr></thead><tbody>
@forelse($sales as $sale)<tr><td>#{{ $sale->id }} @if($sale->status==='cancelled')<span class="badge danger">Cancelada</span>@endif</td><td>{{ \App\Services\Tenant::money($sale->total) }}</td><td><a href="{{ url('/receipt/'.$sale->receipt_hash) }}" target="_blank" rel="noopener">Ver comprovante ↗</a></td></tr>
@empty<tr><td colspan="3">Nenhuma compra neste mês.</td></tr>@endforelse
</tbody></table></div>{{ $sales->links() }}</section>
@if($accounts->isNotEmpty())<section class="card"><h2>Valores pendentes</h2><p class="muted">Somente valores ainda em aberto, incluindo meses anteriores.</p>@include('components.accounts',['readonly'=>true])</section>@endif
@endsection
