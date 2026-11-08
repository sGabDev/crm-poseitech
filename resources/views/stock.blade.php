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
<tbody>@forelse($products as $p)<tr>
<td>{{ $p->name }}</td>
<td>{{ $p->stock }}</td>
<td>{{ $p->min_stock }}</td>
<td>
<span class="badge {{ $p->stock <= $p->min_stock ? 'danger' : '' }}">{{ $p->stock <= $p->min_stock ? 'Repor estoque' : 'Em estoque' }}</span>
</td>
</tr>@empty<tr><td colspan="4" class="empty">Nenhum produto cadastrado para acompanhar o estoque.</td></tr>@endforelse</tbody>
</table>
</div>
</section>
<section class="card">
<h2>Movimentar estoque</h2>
<form method="post" action="{{ url('/stock') }}" class="stack">@csrf<label>Produto<div class="search-picker"><input type="search" class="search-input" placeholder="Pesquisar produto" required autocomplete="off"><div class="search-results" hidden></div></div><select name="product_id" data-search-select="Produto" hidden><option value="">Selecione...</option>@foreach($products as $p)<option value="{{ $p->id }}" @selected((string)old('product_id')===(string)$p->id)>{{ $p->name }}</option>@endforeach</select>
</label>
<label>Tipo<select name="type">
<option value="in" @selected(old('type','in')==='in')>Entrada</option>
<option value="out" @selected(old('type')==='out')>Saída</option>
<option value="adjustment" @selected(old('type')==='adjustment')>Ajustar saldo final</option>
<option value="loss" @selected(old('type')==='loss')>Perda</option>
<option value="return" @selected(old('type')==='return')>Devolução</option>
</select>
</label>
<label>Quantidade / novo saldo<input type="number" name="quantity" min="0" max="1000000" value="{{ old('quantity') }}" required>
</label>
<label>Motivo<input name="notes" maxlength="255" value="{{ old('notes') }}" required>
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
<tbody>@forelse($movements as $m)<tr>
<td>{{ $m->created_at }}</td>
<td>#{{ $m->product_id }}</td>
<td>{{ ['in'=>'Entrada','out'=>'Saída','adjustment'=>'Ajuste','loss'=>'Perda','return'=>'Devolução','sale'=>'Venda'][$m->type] ?? $m->type }}</td>
<td>{{ $m->quantity }}</td>
<td>{{ $m->balance }}</td>
<td>{{ $m->notes }}</td>
</tr>@empty<tr><td colspan="6" class="empty">Nenhuma movimentação registrada.</td></tr>@endforelse</tbody>
</table>
</div>{{ $movements->links() }}</section>@endsection
