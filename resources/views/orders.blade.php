@extends('layout')
@section('title','Pedidos')
@section('content')<div class="page-heading">
<div>
<h1>Pedidos</h1>
<p>Acompanhe a operação em cada etapa.</p>
</div>
<a class="button" href="{{ url('/sales/new') }}">+ Venda com pedido</a>
</div>
<section class="card"><h2>Pedidos do catálogo</h2>@forelse($onlineOrders as $online)<details><summary>#{{ $online->id }} · {{ $online->name }} · {{ \App\Services\Tenant::money($online->total) }} · {{ ['pending'=>'Aguardando atendimento','converted'=>'Venda registrada','cancelled'=>'Cancelado'][$online->status] }}</summary><p>Telefone: {{ $online->phone }}</p><p>Endereço: {{ $online->address ?: 'Retirada / a combinar' }}</p><p>{{ $online->notes }}</p><ul>@foreach(json_decode($online->items,true) as $item)<li>{{ $item['quantity'] }} × {{ $item['name'] }} · {{ \App\Services\Tenant::money($item['price']) }}</li>@endforeach</ul>@if($online->status==='pending' && auth()->user()->allows('orders',true))<form method="post" action="{{ url('/catalog-orders/'.$online->id) }}" class="actions">@csrf<button name="action" value="prepare">Conferir e registrar venda</button><button name="action" value="cancel" class="secondary">Cancelar solicitação</button></form>@elseif($online->sale_id)<a href="{{ url('/sales/'.$online->sale_id) }}">Ver venda #{{ $online->sale_id }}</a>@endif</details>@empty<p>Nenhum pedido do catálogo aguardando atendimento.</p>@endforelse{{ $onlineOrders->links() }}</section><div class="kanban">@foreach(config('poseitech.order_statuses') as $status=>$label)@continue(in_array($status,['delivered','cancelled']) || ($status==='shipping' && !$company->enabled('delivery')))<section class="kanban-column">
<h2>{{ $label }} · {{ $orders->where('status',$status)->count() }}</h2>@foreach($orders->where('status',$status) as $o)@continue($o->delivery && !$company->enabled('delivery'))<article class="card">
<h3>Pedido #{{ $o->id }}</h3>
<a href="{{ url('/sales/'.$o->sale_id) }}">Venda #{{ $o->sale_id }}</a>@if($o->customer_name)<p>{{ $o->customer_name }}</p>@endif @if($o->address ?: $o->customer_address)<p><strong>Endereço:</strong> {{ $o->address ?: $o->customer_address }}</p>@endif @if($o->delivery)
<small>{{ $o->region }} · Taxa {{ \App\Services\Tenant::money($o->fee) }}</small>@endif<form method="post" action="{{ url('/orders/'.$o->id) }}" class="stack">@csrf<label>Próxima etapa<select name="status">@php($next=['received'=>'confirmed','confirmed'=>'preparing','preparing'=>'ready','ready'=>$o->delivery ? 'shipping' : 'delivered','shipping'=>'delivered'][$status])<option value="{{ $next }}">{{ config('poseitech.order_statuses.'.$next) }}</option>
</select>
</label>@if($o->delivery)<label>Entregador<select name="driver_id">
<option value="">Selecione</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected($o->driver_id===$driver->id)>{{ $driver->name }}</option>@endforeach</select>
</label>
<label>Previsão<input type="datetime-local" name="estimated_at" value="{{ $o->estimated_at }}">
</label>@endif<button>Avançar pedido</button>
</form>
</article>@endforeach</section>@endforeach</div>
<section class="card">
<h2>Histórico</h2>@foreach($history as $o)<div class="metric-row">
<a href="{{ url('/sales/'.$o->sale_id) }}">Pedido #{{ $o->id }} · Venda #{{ $o->sale_id }}</a>
<span>{{ config('poseitech.order_statuses.'.$o->status) }}</span>
</div>@endforeach{{ $history->links() }}</section>@endsection
