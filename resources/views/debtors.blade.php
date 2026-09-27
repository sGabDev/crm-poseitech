@extends('layout')
@section('title','Fiados')
@section('content')
<div class="page-heading"><div><h1>Fiados</h1><p>Receba qualquer valor da dívida acumulada do cliente.</p></div></div>
<div class="kpi"><span>Total em fiado</span><strong>{{ \App\Services\Tenant::money($total) }}</strong></div>
<form class="filters">
<label>Cliente<input name="q" value="{{ request('q') }}"></label>
<label>Ordenar<select name="sort">@foreach(['balance'=>'Maior dívida','recent'=>'Últimos fiados','oldest'=>'Vencimento mais antigo','name'=>'Nome do cliente'] as $key=>$label)<option value="{{ $key }}" @selected(request('sort','balance')===$key)>{{ $label }}</option>@endforeach</select></label>
<button class="secondary">Filtrar</button></form>
@forelse($customers as $customer)
<section class="card">
<div class="page-heading"><div><h2><a href="{{ url('/customers/'.$customer->id) }}">{{ $customer->name }}</a></h2><p>{{ $customer->phone }} · Vencimento mensal: dia {{ $customer->due_day }}</p></div><strong class="badge danger">{{ \App\Services\Tenant::money(-$customer->debt) }}</strong></div>
<p>Último fiado: {{ \Carbon\Carbon::parse($customer->last_credit)->format('d/m/Y') }} · Primeiro vencimento em aberto: {{ \Carbon\Carbon::parse($customer->next_due)->format('d/m/Y') }}</p>
@if(auth()->user()->allows('credit',true))
<form method="post" action="{{ url('/customers/'.$customer->id.'/debt-payment') }}" class="filters">@csrf
<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label>Valor recebido<input name="amount" type="number" step="0.01" min="0.01" max="{{ number_format($customer->debt/100,2,'.','') }}" required></label>
<label>Forma de pagamento<select name="method">@foreach(config('poseitech.methods') as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
<button>Abater da dívida</button></form>
@endif
</section>
@empty
<section class="card empty">Nenhum cliente com fiado em aberto.</section>
@endforelse
{{ $customers->links() }}
@endsection
