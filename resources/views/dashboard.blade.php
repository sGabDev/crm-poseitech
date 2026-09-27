@extends('layout')
@section('title','Visão geral')
@section('content')
<div class="page-heading">
<div>
<div class="eyebrow">SEU NEGÓCIO EM MOVIMENTO</div>
<h1>Visão geral</h1>
<p>Acompanhe os resultados e descubra o próximo passo.</p>
</div>@if(auth()->user()->allows('sales',true))<a class="button" href="{{ url('/sales/new') }}">+ Nova venda</a>@endif</div>
@include('components.period')
<div class="actions" style="margin-bottom:18px"><span class="badge">Hoje: {{ \App\Services\Tenant::money($todayRevenue) }}</span><span class="badge">Mês atual: {{ \App\Services\Tenant::money($monthRevenue) }}</span></div>
<div class="kpi-grid">
<article class="kpi featured">
<span>Faturamento no período</span>
<strong>{{ \App\Services\Tenant::money($totals->revenue) }}</strong>
<small>{{ $previous ? sprintf('%+.1f%%',($totals->revenue/$previous-1)*100).' em relação ao período anterior' : 'Sem base no período anterior' }}</small>
</article>
<article class="kpi">
<span>Vendas realizadas</span>
<strong>{{ $totals->count }}</strong>
<small>Vendas concluídas no período</small>
</article>
<article class="kpi">
<span>Ticket médio</span>
<strong>{{ \App\Services\Tenant::money($ticket) }}</strong>
<small>Valor médio por venda</small>
</article>
<article class="kpi">
<span>Novos clientes</span>
<strong>{{ $newCustomers }}</strong>
<small>{{ $recurring }} recorrentes na sua base</small>
</article>
</div>
<div class="grid wide-left">
<section class="card">
<div class="section-heading">
<div>
<h2>Evolução das vendas</h2>
<p>Faturamento por dia</p>
</div>
<span class="badge">{{ $days }} dias</span>
</div>
@if($daily->isNotEmpty())<div class="bar-chart" role="img" aria-label="Faturamento diário">
<div class="bars">@foreach($daily as $day)<div class="bar-column">
<span class="bar-value">{{ \App\Services\Tenant::money($day->total) }}</span>
<div class="bar" style="height:{{ max(3,($day->total/max(1,$daily->max('total')))*170) }}px" title="{{ $day->day }}: {{ \App\Services\Tenant::money($day->total) }}">
</div>
<small>{{ \Carbon\Carbon::parse($day->day)->format('d/m') }}</small>
</div>@endforeach</div>
</div>@else<div class="empty">
<span>↗</span>
<h3>Seu próximo resultado começa aqui</h3>
<p>Registre a primeira venda para acompanhar a evolução.</p>
<a href="{{ url('/sales/new') }}">Registrar venda →</a>
</div>@endif</section>
<section class="card">
<div class="section-heading">
<h2>Formas de pagamento</h2>
<span class="dot">
</span>
</div>@forelse($methods as $method)<div class="metric-row">
<span>{{ config('poseitech.payment_labels.'.$method->method) }}</span>
<strong>{{ \App\Services\Tenant::money($method->total) }}</strong>
</div>
<div class="progress">
<span style="width:{{ $method->total/max(1,$incoming)*100 }}%">
</span>
</div>@empty<p class="empty">Os recebimentos aparecerão aqui.</p>@endforelse</section>
</div>
@if($company->enabled('finance') && auth()->user()->allows('finance'))<div class="kpi-grid small">
<article class="kpi">
<span>Recebido no período</span>
<strong>{{ \App\Services\Tenant::money($incoming) }}</strong>
<small>Pagamentos confirmados</small>
</article>
<article class="kpi">
<span>A receber</span>
<strong>{{ \App\Services\Tenant::money($receivable) }}</strong>
<small>Saldo pendente total</small>
</article>
<article class="kpi">
<span>Contas vencidas</span>
<strong class="warning-text">{{ $overdue }}</strong>
<a href="{{ url('/finance?status=overdue') }}">Acompanhar vencimentos →</a>
</article>
<article class="kpi">
<span>Dinheiro em caixa</span>
<strong>{{ \App\Services\Tenant::money($cash) }}</strong>
<small>Saldo do caixa aberto</small>
</article>
</div>@endif
<div class="opportunity-banner">
<div>
<span class="eyebrow">HORA DE AGIR</span>
<h2>{{ $inactive ? $inactive.' clientes podem estar esperando seu contato.' : 'Transforme cada venda em um novo relacionamento.' }}</h2>
<p>Encontre oportunidades a partir dos dados do seu negócio.</p>
</div>
<a class="button secondary" href="{{ url('/opportunities') }}">Ver oportunidades ↗</a>
</div>
<div class="grid">
<section class="card">
<div class="section-heading">
<h2>Últimas vendas</h2>
<a href="{{ url('/sales') }}">Ver todas →</a>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Venda</th>
<th>Data</th>
<th>Total</th>
<th>Status</th>
</tr>
</thead>
<tbody>@forelse($recent as $sale)<tr>
<td>
<a href="{{ url('/sales/'.$sale->id) }}">#{{ $sale->id }}</a>
</td>
<td>{{ \Carbon\Carbon::parse($sale->created_at)->format('d/m H:i') }}</td>
<td>{{ \App\Services\Tenant::money($sale->total) }}</td>
<td>
<span class="badge">{{ $sale->status==='cancelled' ? 'Cancelada' : 'Concluída' }}</span>
</td>
</tr>@empty<tr>
<td colspan="4" class="empty">Nenhuma venda registrada.</td>
</tr>@endforelse</tbody>
</table>
</div>
</section>
<section class="card">
<div class="section-heading">
<h2>Mais vendidos</h2>
<span class="muted">No período</span>
</div>@forelse($top as $p)<div class="rank-row">
<span class="rank">{{ $loop->iteration }}</span>
<div>
<strong>{{ $p->name }}</strong>
<small>{{ $p->quantity }} unidades</small>
</div>
<strong>{{ \App\Services\Tenant::money($p->total) }}</strong>
</div>@empty<div class="empty">Descubra seus destaques após registrar vendas.</div>@endforelse</section>
</div>
@if($goals->isNotEmpty())<section class="card">
<h2>Suas metas</h2>@foreach($goals as $goal)<div class="metric-row">
<strong>{{ $goal->name }}</strong>
<span>{{ round($goal->actual/max(1,$goal->target)*100) }}%</span>
</div>
<div class="progress">
<span style="width:{{ min(100,$goal->actual/max(1,$goal->target)*100) }}%">
</span>
</div>@endforeach</section>@endif
@endsection
