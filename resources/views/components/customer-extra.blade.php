<section class="card">
<h2>Preferências e cupons</h2>
<p>Pagamento mais utilizado: <strong>{{ config('poseitech.methods.'.$preferred) ?? 'Sem histórico' }}</strong>
</p>@foreach($usedCoupons as $used)<div class="metric-row">
<span>{{ $used->code }} · Venda #{{ $used->id }}</span>
<small>{{ $used->created_at }}</small>
</div>@endforeach</section>
<section class="card">
<h2>Filtrar histórico de compras</h2>
<form class="filters">
<input type="hidden" name="period" value="{{ request('period','month') }}">
<label>Venda ID<input type="number" name="sale" min="1" value="{{ request('sale') }}">
</label>
<label>Produto ID<input type="number" name="product" min="1" value="{{ request('product') }}">
</label>
<label>Status<select name="status">
<option value="">Todos</option>
<option value="completed" @selected(request('status')==='completed')>Concluída</option>
<option value="cancelled" @selected(request('status')==='cancelled')>Cancelada</option>
</select>
</label>
<button class="secondary">Filtrar</button>
</form>
</section>
