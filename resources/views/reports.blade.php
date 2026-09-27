@extends('layout')
@section('title','Relatórios e previsão')
@section('content')<div class="page-heading">
<div>
<h1>Relatórios e previsão</h1>
<p>Resultados para orientar suas próximas decisões.</p>
</div>
<div class="actions">
<a class="button secondary" href="{{ request()->fullUrlWithQuery(['export'=>'csv']) }}">Exportar CSV</a>
<button class="secondary" data-print>Imprimir / PDF</button>
</div>
</div>@include('components.period')<div class="kpi-grid">
<div class="kpi">
<span>Faturamento</span>
<strong>{{ \App\Services\Tenant::money($totals->revenue) }}</strong>
</div>
<div class="kpi">
<span>Recebimentos</span>
<strong>{{ \App\Services\Tenant::money($incoming) }}</strong>
</div>
<div class="kpi">
<span>Pagamentos</span>
<strong>{{ \App\Services\Tenant::money($outgoing) }}</strong>
</div>
<div class="kpi">
<span>Fluxo líquido</span>
<strong>{{ \App\Services\Tenant::money($incoming-$outgoing) }}</strong>
</div>
</div>
<div class="grid">
<section class="card">
<h2>DRE simplificada</h2>
<p class="muted">Visão gerencial estimada. Não é contabilidade fiscal oficial. Despesas por vencimento no período.</p>@foreach(['Receita bruta'=>$totals->gross,'(-) Descontos'=>-$totals->discount,'(+) Acréscimos'=>$totals->extra,'(-) Custos dos itens'=>-$totals->cost,'(-) Despesas'=>-$expenses,'Resultado estimado'=>$totals->revenue-$totals->cost-$expenses] as $label=>$value)<div class="metric-row">
<span>{{ $label }}</span>
<strong>{{ \App\Services\Tenant::money($value) }}</strong>
</div>@endforeach</section>
<section class="card">
<h2>Previsão de vendas</h2>
<p class="muted">Estimativa pela média dos últimos 30 dias completos. Não representa garantia de resultado.</p>@foreach(['Média diária / amanhã'=>$forecast,'Próximos 7 dias'=>$forecast*7,'Restante do mês'=>$forecast*(now()->daysInMonth-now()->day),'Próximo mês'=>$forecast*now()->addMonthNoOverflow()->daysInMonth] as $label=>$value)<div class="metric-row">
<span>{{ $label }}</span>
<strong>{{ \App\Services\Tenant::money($value) }}</strong>
</div>@endforeach</section>
</div>
<div class="grid">
<section class="card">
<h2>Vendas por vendedor</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Vendedor</th>
<th>Vendas</th>
<th>Total</th>
<th>Ticket</th>
</tr>
</thead>
<tbody>@foreach($sellers as $s)<tr>
<td>{{ $s->name }}</td>
<td>{{ $s->count }}</td>
<td>{{ \App\Services\Tenant::money($s->total) }}</td>
<td>{{ \App\Services\Tenant::money($s->ticket) }}</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>
<section class="card">
<h2>Vendas por categoria</h2>@foreach($categories as $c)<div class="metric-row">
<span>{{ $c->category ?: 'Sem categoria' }}</span>
<strong>{{ \App\Services\Tenant::money($c->total) }}</strong>
</div>@endforeach</section>
<section class="card">
<h2>Produtos mais vendidos</h2>@foreach($top as $p)<div class="metric-row">
<span>{{ $p->name }} · {{ $p->quantity }} un.</span>
<strong>{{ \App\Services\Tenant::money($p->total) }}</strong>
</div>@endforeach</section>
<section class="card">
<h2>Formas de pagamento</h2>@foreach($methods as $m)<div class="metric-row">
<span>{{ config('poseitech.payment_labels.'.$m->method) }}</span>
<strong>{{ \App\Services\Tenant::money($m->total) }}</strong>
</div>@endforeach</section>
<section class="card">
<h2>Campanhas e fidelidade</h2>@foreach($campaignResults as $result)<div class="metric-row">
<span>{{ $result->status }}</span>
<strong>{{ $result->count }} e-mails</strong>
</div>@endforeach<p>Saldo de fidelidade: {{ $loyaltyBalance }} pontos / benefícios.</p>
</section>
<section class="card">
<h2>Clientes</h2>
<div class="metric-row">
<span>Novos no período</span>
<strong>{{ $newCustomers }}</strong>
</div>
<div class="metric-row">
<span>Recorrentes na base</span>
<strong>{{ $recurring }}</strong>
</div>
<div class="metric-row">
<span>Inativos há 30 dias</span>
<strong>{{ $inactive }}</strong>
</div>
</section>
</div>@include('components.report-details')
@include('components.report-email')
@endsection
