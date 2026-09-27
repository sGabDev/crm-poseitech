@extends('layout')
@section('title',$spec['title'])
@section('content')
<div class="page-heading">
<div>
<div class="eyebrow">CADASTROS DA EMPRESA</div>
<h1>{{ $spec['title'] }}</h1>
<p>Informações organizadas para uma operação mais simples.</p>
</div>@if(auth()->user()->allows($spec['module'],true))<a class="button" href="{{ url('/records/'.$resource.'/new') }}">+ Novo cadastro</a>@endif</div>
<form class="filters">
<label>Pesquisar<input name="q" value="{{ request('q') }}" placeholder="Nome ou código">
</label>@if($resource==='customers')<label>Segmento<select name="segment">@foreach(['all'=>'Todos','new'=>'Novos','recurring'=>'Recorrentes','vip'=>'VIP','inactive'=>'Inativos','pending'=>'Com pendências','birthday'=>'Aniversariantes'] as $k=>$v)<option value="{{ $k }}" @selected(request('segment')===$k)>{{ $v }}</option>@endforeach</select>
</label>@endif<button class="secondary">Filtrar</button>
</form>
<section class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Nome / código</th>@foreach(array_slice($spec['fields'],1,3,true) as $key=>$f)<th>{{ $f[0] }}</th>@endforeach<th>Ações</th>
</tr>
</thead>
<tbody>@forelse($records as $record)<tr>
<td>
<strong>{{ $record->name ?? $record->code }}</strong>
</td>@foreach(array_slice($spec['fields'],1,3,true) as $key=>$f)<td>@if($f[1]==='money'){{ \App\Services\Tenant::money($record->$key) }}@elseif(str_starts_with($f[1],'select:')){{ collect(explode(',',substr($f[1],7)))->mapWithKeys(fn($o)=>[explode('=',$o)[0]=>explode('=',$o)[1]])[$record->$key] ?? $record->$key }}@else{{ $record->$key ?? '—' }}@endif</td>@endforeach<td>
<div class="actions">@if($resource==='customers')<a href="{{ url('/customers/'.$record->id) }}">Ver perfil</a>@endif @if(auth()->user()->allows($spec['module'],true))<a class="record-action" href="{{ url('/records/'.$resource.'/'.$record->id.'/edit') }}">Editar</a>@if(in_array($resource,['suppliers','goals','products']))<form method="post" action="{{ url('/records/'.$resource.'/'.$record->id.'/delete') }}" data-confirm="Excluir este cadastro?">@csrf<button class="record-action">Excluir</button></form>@endif
@endif</div>
</td>
</tr>@empty<tr>
<td colspan="5">
<div class="empty">
<h3>Seu primeiro cadastro começa aqui</h3>
<p>Adicione informações para começar a usar este módulo.</p>
</div>
</td>
</tr>@endforelse</tbody>
</table>
</div>{{ $records->links() }}</section>
@endsection
