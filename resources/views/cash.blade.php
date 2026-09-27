@extends('layout')
@section('title','Caixa')
@section('content')<div class="page-heading">
<div>
<h1>Controle de caixa</h1>
<p>Saldo em dinheiro separado dos recebimentos eletrônicos.</p>
</div>
<span class="badge">{{ $register ? 'Caixa aberto' : 'Caixa fechado' }}</span>
</div>
@if($register)<div class="kpi-grid">
<div class="kpi">
<span>Fundo inicial</span>
<strong>{{ \App\Services\Tenant::money($register->opening) }}</strong>
</div>
<div class="kpi featured">
<span>Saldo esperado em dinheiro</span>
<strong>{{ \App\Services\Tenant::money($expected) }}</strong>
</div>
<div class="kpi">
<span>Entradas da sessão</span>
<strong>{{ \App\Services\Tenant::money($transactions->where('amount','>',0)->sum('amount')) }}</strong>
</div>
<div class="kpi">
<span>Saídas da sessão</span>
<strong>{{ \App\Services\Tenant::money(-$transactions->where('amount','<',0)->sum('amount')) }}</strong>
</div>
</div>
<div class="grid">
<section class="card">
<h2>Reforço / sangria</h2>
<form method="post" action="{{ url('/cash') }}" class="stack">@csrf<label>Movimentação<select name="action">
<option value="in">Reforço de caixa</option>
<option value="out">Sangria / retirada</option>
</select>
</label>
<label>Valor ({{ $company->currency ?? 'BRL' }})<input type="number" step="0.01" min="0.01" name="amount" required>
</label>
<label>Motivo<input name="description" required maxlength="255">
</label>
<button>Registrar</button>
</form>
</section>
<section class="card">
<h2>Fechamento</h2>
<p class="muted">Conte o dinheiro disponível. A diferença ficará registrada.</p>
<form method="post" action="{{ url('/cash') }}" class="stack">@csrf<input type="hidden" name="action" value="close">
<label>Dinheiro contado ({{ $company->currency ?? 'BRL' }})<input type="number" name="amount" min="0" step="0.01" required>
</label>
<button>Fechar caixa</button>
</form>
<hr>@foreach($transactions->groupBy('method') as $method=>$rows)<div class="metric-row">
<span>{{ config('poseitech.payment_labels.'.$method) }}</span>
<strong>{{ \App\Services\Tenant::money($rows->sum('amount')) }}</strong>
</div>@endforeach</section>
</div>
<section class="card">
<h2>Movimentações desta sessão</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Data</th>
<th>Descrição</th>
<th>Forma</th>
<th>Valor</th>
</tr>
</thead>
<tbody>@foreach($transactions as $entry)<tr>
<td>{{ \Carbon\Carbon::parse($entry->created_at)->timezone($company->timezone)->format('d/m/Y H:i:s') }}</td>
<td>{{ $entry->description }}</td>
<td>{{ config('poseitech.payment_labels.'.$entry->method) }}</td>
<td>{{ \App\Services\Tenant::money($entry->amount) }}</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>
@else<section class="card">
<h2>Abrir caixa</h2>
<form method="post" action="{{ url('/cash') }}" class="filters">@csrf<input type="hidden" name="action" value="open">
<label>Fundo inicial em dinheiro ({{ $company->currency ?? 'BRL' }})<input name="amount" type="number" min="0" step="0.01" value="0" required>
</label>
<button>Abrir caixa</button>
</form>
</section>@endif
<section class="card">
<h2>Histórico de caixas</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Abertura</th>
<th>Fechamento</th>
<th>Responsável ID</th>
<th>Esperado</th>
<th>Contado</th>
<th>Diferença</th>
</tr>
</thead>
<tbody>@foreach($history as $h)<tr>
<td>{{ \Carbon\Carbon::parse($h->created_at)->timezone($company->timezone)->format('d/m/Y H:i:s') }}</td>
<td>{{ $h->closed_at ? \Carbon\Carbon::parse($h->closed_at)->timezone($company->timezone)->format('d/m/Y H:i:s') : 'Aberto' }}</td>
<td>#{{ $h->user_id }}</td>
<td>{{ \App\Services\Tenant::money($h->expected) }}</td>
<td>{{ \App\Services\Tenant::money($h->counted) }}</td>
<td>{{ \App\Services\Tenant::money($h->difference) }}</td>
</tr>@endforeach</tbody>
</table>
</div>{{ $history->links() }}</section>@endsection
