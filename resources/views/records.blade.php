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
</label>@endif @if($resource==='coupons')<label>Status<select name="status">@foreach([''=>'Todos','active'=>'Ativos','expired'=>'Vencidos','used'=>'Já utilizados','inactive'=>'Inativos'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label>@endif<button class="secondary">Filtrar</button>
</form>
<section class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Nome / código</th>@foreach(array_slice($spec['fields'],1,3,true) as $key=>$f)<th>{{ $f[0] }}</th>@endforeach
@if($resource==='coupons')<th>Status / validade</th>@endif<th>Ações</th>
</tr>
</thead>
<tbody>@forelse($records as $record)<tr>
<td>
<strong>{{ $record->name ?? $record->code }}</strong>
</td>@foreach(array_slice($spec['fields'],1,3,true) as $key=>$f)<td>@if($f[1]==='product'){{ $couponProducts[$record->$key] ?? '—' }}@elseif($f[1]==='money'){{ \App\Services\Tenant::money($record->$key) }}@elseif(str_starts_with($f[1],'select:')){{ collect(explode(',',substr($f[1],7)))->mapWithKeys(fn($o)=>[explode('=',$o)[0]=>explode('=',$o)[1]])[$record->$key] ?? $record->$key }}@else{{ $record->$key ?? '—' }}@endif</td>@endforeach @if($resource==='coupons')<td><span class="badge">{{ !$record->active ? 'Inativo' : ($record->expires_at < now($company->timezone)->toDateString() ? 'Vencido' : ($record->uses >= $record->max_uses ? 'Já usado / esgotado' : 'Ativo')) }}</span><small>{{ $record->uses }}/{{ $record->max_uses }} usos · {{ \Carbon\Carbon::parse($record->expires_at)->format('d/m/Y') }}</small></td>@endif<td>
<div class="actions">@if($resource==='customers')<a href="{{ url('/customers/'.$record->id) }}">Ver perfil</a>@endif @if(auth()->user()->allows($spec['module'],true))<a class="record-action" href="{{ url('/records/'.$resource.'/'.$record->id.'/edit') }}">Editar</a>@if(in_array($resource,['suppliers','goals','products','coupons']))<form method="post" action="{{ url('/records/'.$resource.'/'.$record->id.'/delete') }}" data-confirm="Excluir este cadastro?">@csrf<button class="record-action">Excluir</button></form>@endif
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
@if($resource==='coupons')<section class="card"><h2>Cupons conquistados: envio e uso</h2><p>A compra mínima libera o cupom uma vez por cliente para uma próxima compra. Sem compra mínima, o código continua livre para uso.</p><div class="table-wrap"><table><thead><tr><th>Cliente / cupom</th><th>Compra que liberou</th><th>E-mail</th><th>Uso</th></tr></thead><tbody>@forelse($grants as $grant)<tr><td>{{ $grant->name }} / {{ $grant->code }}</td><td>#{{ $grant->earned_sale_id }}</td><td>{{ ['pending'=>'Na fila','sent'=>'Enviado','failed'=>'Falhou','sending'=>'Enviando','cancelled'=>'Cancelado'][$grant->mail_status] ?? 'Sem e-mail válido' }} {{ $grant->sent_at }}</td><td>{{ $grant->revoked_at ? 'Revogado' : ($grant->used_sale_id ? 'Usado na venda #'.$grant->used_sale_id : 'Disponível') }}</td></tr>@empty<tr><td colspan="4">Nenhum cupom conquistado ainda.</td></tr>@endforelse</tbody></table></div>{{ $grants->links() }}</section>@endif
@if($resource==='coupons')<section class="card"><h2>Histórico de resgates</h2>@forelse($couponUses as $use)<div class="metric-row"><span>{{ $use->code }} · {{ $use->name ?: 'Consumidor não identificado' }} · {{ \Carbon\Carbon::parse($use->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</span><span>Venda #{{ $use->id }} · {{ $use->status==='cancelled' ? 'Cancelado' : 'Utilizado' }}</span></div>@empty<p>Nenhum resgate registrado.</p>@endforelse{{ $couponUses->links() }}</section>@endif
@endsection
