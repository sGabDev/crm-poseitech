@extends('layout',['public'=>true])
@section('title','Comprovante de compra')
@section('content')<section class="card receipt">
<div class="section-heading">
<div>
<div class="eyebrow">COMPROVANTE DIGITAL</div>
@if($company->logo)<img src="{{ url('/logo/'.$company->id) }}" alt="{{ $company->name }}" width="90">@endif
<h1>{{ $company->name }}</h1>
<p>{{ $company->document }} · {{ $company->address }}</p>
</div>
<button data-print class="secondary">Imprimir / salvar PDF</button>
</div>
<h2>Venda #{{ $sale->id }}</h2>
<p>{{ \Carbon\Carbon::parse($sale->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</p>@if($sale->status==='cancelled')<div class="notice error">VENDA CANCELADA</div>@endif @include('components.items')<div class="metric-row">
<span>Subtotal</span>
<strong>{{ \App\Services\Tenant::money($sale->subtotal) }}</strong>
</div>
<div class="metric-row">
<span>Desconto</span>
<strong>{{ \App\Services\Tenant::money($sale->discount) }}</strong>
</div>
<div class="metric-row">
<span>Acréscimos</span>
<strong>{{ \App\Services\Tenant::money($sale->extra) }}</strong>
</div>
<div class="metric-row total">
<span>Total</span>
<strong>{{ \App\Services\Tenant::money($sale->total) }}</strong>
</div>
<h2>Pagamentos</h2>@foreach($payments as $p)<div class="metric-row">
<span>{{ config('poseitech.payment_labels.'.$p->method) }} {{ $p->reversed_at ? ' · Estornado' : '' }}</span>
<strong>{{ \App\Services\Tenant::money($p->amount) }}</strong>
</div>@endforeach<p>Saldo pendente: {{ \App\Services\Tenant::money($sale->status==='cancelled' ? 0 : $sale->total-$sale->paid) }}</p>
<hr>
<p class="muted">Comprovante de operação comercial. Não substitui documento fiscal.</p>
</section>@endsection
