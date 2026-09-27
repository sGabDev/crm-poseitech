@extends('layout')
@section('title','Busca global')
@section('content')<div class="page-heading">
<div>
<h1>Resultados para “{{ $term }}”</h1>
<p>Digite pelo menos dois caracteres para pesquisar.</p>
</div>
</div>@forelse($results as $table=>$rows)<section class="card">
<h2>{{ ['customers'=>'Clientes','products'=>'Produtos','sales'=>'Vendas','orders'=>'Pedidos','accounts'=>'Contas'][$table] }}</h2>@forelse($rows as $row)<div class="metric-row">
<a href="{{ url(match($table){'customers'=>'/customers/'.$row->id,'products'=>'/records/products?q='.urlencode($row->name),'sales'=>'/sales/'.$row->id,'orders'=>'/orders',default=>'/finance'}) }}">{{ $row->name ?? $row->description ?? '#'.$row->id }}</a>
<small>#{{ $row->id }}</small>
</div>@empty<p class="muted">Nenhum resultado.</p>@endforelse</section>@empty<div class="card empty">Nenhum resultado disponível.</div>@endforelse
@endsection
