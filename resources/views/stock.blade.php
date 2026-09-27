@extends('layout')
@section('title','Estoque')
@section('content')<div class="page-heading">
<div>
<h1>Estoque</h1>
<p>Reposição, perdas e movimentações com rastreabilidade.</p>
</div>
</div>
<div class="grid wide-left">
<section class="card">
<h2>Posição atual</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Produto</th>
<th>Disponível</th>
<th>Mínimo</th>
<th>Situação</th>
</tr>
</thead>
<tbody>@foreach($products as $p)<tr>
<td>{{ $p->name }}</td>
<td>{{ $p->stock }}</td>
<td>{{ $p->min_stock }}</td>
<td>
<span class="badge {{ $p->stock <= $p->min_stock ? 'danger' : '' }}">{{ $p->stock <= $p->min_stock ? 'Repor estoque' : 'Em estoque' }}</span>
</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>
<section class="card">
<h2>Movimentar estoque</h2>
<form method="post" action="{{ url('/stock') }}" class="stack">@csrf<label>Produto<select name="product_id" required>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
</label>
<label>Tipo<select name="type">
<option value="in">Entrada</option>
<option value="out">Saída</option>
<option value="adjustment">Ajustar saldo final</option>
<option value="loss">Perda</option>
<option value="return">Devolução</option>
</select>
</label>
<label>Quantidade / novo saldo<input type="number" name="quantity" min="0" required>
</label>
<label>Motivo<input name="notes" required>
</label>
<button>Registrar movimento</button>
</form>
</section>
</div>
<section class="card">
<h2>Histórico</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Data</th>
<th>Produto ID</th>
<th>Tipo</th>
<th>Movimento</th>
<th>Saldo</th>
<th>Motivo</th>
</tr>
</thead>
<tbody>@foreach($movements as $m)<tr>
<td>{{ $m->created_at }}</td>
<td>#{{ $m->product_id }}</td>
<td>{{ ['in'=>'Entrada','out'=>'Saída','adjustment'=>'Ajuste','loss'=>'Perda','return'=>'Devolução','sale'=>'Venda'][$m->type] ?? $m->type }}</td>
<td>{{ $m->quantity }}</td>
<td>{{ $m->balance }}</td>
<td>{{ $m->notes }}</td>
</tr>@endforeach</tbody>
</table>
</div>{{ $movements->links() }}</section>@endsection
