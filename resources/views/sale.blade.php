@extends('layout')
@section('title','Detalhes da venda')
@section('content')<div class="page-heading">
<div>
<a href="{{ url('/sales') }}">← Vendas</a>
<h1>Venda #{{ $sale->id }}</h1>
<p>{{ $customer->name ?? 'Consumidor não identificado' }} · {{ \Carbon\Carbon::parse($sale->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</p>
</div>
<a class="button" href="{{ url('/receipt/'.$sale->receipt_hash) }}" target="_blank">Comprovante digital ↗</a>
</div>
<div class="kpi-grid">
<div class="kpi">
<span>Total</span>
<strong>{{ \App\Services\Tenant::money($sale->total) }}</strong>
</div>
<div class="kpi">
<span>Recebido</span>
<strong>{{ \App\Services\Tenant::money($sale->paid) }}</strong>
</div>
<div class="kpi">
<span>Status</span>
<strong>{{ $sale->status==='cancelled' ? 'Cancelada' : 'Concluída' }}</strong>
</div>
</div>
<section class="card">@include('components.items')<p>Desconto: {{ \App\Services\Tenant::money($sale->discount) }} · Acréscimos: {{ \App\Services\Tenant::money($sale->extra) }}</p>
<p>{{ $sale->notes }}</p>
<div class="actions">
<a class="button secondary" href="https://wa.me/?text={{ rawurlencode('Comprovante de compra: '.url('/receipt/'.$sale->receipt_hash)) }}" target="_blank" rel="noopener">Compartilhar por WhatsApp</a>
<form method="post" action="{{ url('/sales/'.$sale->id.'/email') }}">@csrf<button class="secondary">Enviar comprovante por e-mail</button>
</form>
</div>
</section>
<section class="card">
<h2>Pagamentos</h2>@foreach($payments as $p)<div class="metric-row">
<span>{{ config('poseitech.methods.'.$p->method) }} · {{ $p->created_at }} {{ $p->reversed_at ? '(estornado)' : '' }}</span>
<strong>{{ \App\Services\Tenant::money($p->amount) }}</strong>
</div>@endforeach</section>
<section class="card">
<h2>Parcelas</h2>@include('components.accounts')</section>@can('manage-company')@if($sale->status!=='cancelled')<details class="card">
<summary>Cancelar venda e estornar lançamentos</summary>
<form class="filters" method="post" action="{{ url('/sales/'.$sale->id.'/cancel') }}" data-confirm="Cancelar a venda, devolver pagamentos e repor o estoque?">@csrf<label>Motivo<input name="reason" required minlength="5" maxlength="500">
</label>
<button class="danger-button">Confirmar cancelamento</button>
</form>
</details>@endif
@endcan
@endsection
