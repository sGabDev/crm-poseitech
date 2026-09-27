@extends('layout')
@section('title','Nova venda')
@section('content')<div class="page-heading">
<div>
<a href="{{ url('/sales') }}">← Vendas</a>
<h1>Nova venda</h1>
<p>Selecione os itens e informe os valores recebidos.</p>
</div>
</div>
<form method="post" action="{{ url('/sales') }}" id="sale-form" data-currency="{{ $company->currency }}">@csrf<input type="hidden" name="request_key" value="{{ old('request_key', (string)\Illuminate\Support\Str::uuid()) }}">
<div class="grid wide-left">
<div>
<section class="card">
<h2>Cliente e itens</h2>
<label>Cliente<select name="customer_id">
<option value="">Consumidor não identificado</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id')==$c->id)>{{ $c->name }} {{ $c->phone ? ' · '.$c->phone : '' }}</option>@endforeach</select>
</label>
<div id="sale-items">@foreach(old('items',[['product_id'=>'','quantity'=>1]]) as $index=>$line)<div class="sale-line">
<label>Produto ou serviço<select name="items[{{ $index }}][product_id]" class="product-select" required>
<option value="">Selecione...</option>@foreach($products as $p)<option value="{{ $p->id }}" data-price="{{ $p->price }}" data-addons="{{ $p->addons ?? '[]' }}" @selected($line['product_id']==$p->id)>{{ $p->name }} · {{ \App\Services\Tenant::money($p->price) }}</option>@endforeach</select>
</label>
<label>Quantidade<input class="quantity" type="number" name="items[{{ $index }}][quantity]" min="1" max="10000" value="{{ $line['quantity'] }}" required>
</label>
<label class="addon-label">Adicionais<select multiple class="addon-select" name="items[{{ $index }}][addons][]" data-selected="{{ json_encode($line['addons'] ?? []) }}">
</select>
</label>
<button type="button" class="icon-button remove-line" aria-label="Remover item">×</button>
</div>@endforeach</div>
<button type="button" class="secondary" id="add-item">+ Adicionar item</button>
</section>
<section class="card">
<h2>Pagamento</h2>
<p class="muted">Com uma única forma, o valor é preenchido automaticamente. Em pagamentos divididos, o fiado corresponde ao restante.</p>
<input type="hidden" name="auto_payment" id="auto-payment" value="1"><div id="sale-payments">@foreach(old('payments',[['method'=>'pix','amount'=>0]]) as $index=>$payment)<div class="payment-line">
<label>Forma<select name="payments[{{ $index }}][method]">@foreach(config('poseitech.sale_methods') as $k=>$v)<option value="{{ $k }}" @selected($payment['method']===$k)>{{ $v }}</option>@endforeach</select>
</label>
<label>Valor ({{ $company->currency ?? 'BRL' }})<input type="number" class="payment-amount" name="payments[{{ $index }}][amount]" step="0.01" min="0" value="{{ $payment['amount'] }}" required>
</label>
<button type="button" class="icon-button remove-line" aria-label="Remover pagamento">×</button>
</div>@endforeach</div>
<button type="button" id="add-payment" class="secondary">+ Outra forma de pagamento</button>
<p class="muted">O fiado será somado à dívida do cliente, com vencimento no dia definido no cadastro.</p>
</section>
@if($company->enabled('orders'))<section class="card">
<h2>Pedido</h2>
<label class="check">
<input type="checkbox" name="order" value="1" @checked(old('order'))>Criar pedido no Kanban</label>@if($company->enabled('delivery'))<label class="check">
<input type="checkbox" name="delivery" value="1" @checked(old('delivery'))>Entrega em endereço</label>
<div class="form-grid">
<label>Endereço<input name="address" value="{{ old('address') }}">
</label>
<label>Região<input name="region" value="{{ old('region') }}">
</label>
<label>Taxa de entrega ({{ $company->currency ?? 'BRL' }})<input id="delivery-fee" type="number" name="fee" step="0.01" min="0" value="{{ old('fee',0) }}">
</label>
</div>@endif</section>@endif</div>
<aside>
<section class="card sale-summary">
<h2>Resumo da venda</h2>
<div class="metric-row">
<span>Subtotal</span>
<strong id="subtotal">R$ 0,00</strong>
</div>
<label>Desconto ({{ $company->currency ?? 'BRL' }})<input id="discount" type="number" name="discount" step="0.01" min="0" value="{{ old('discount',0) }}">
</label>
<label>Acréscimo ({{ $company->currency ?? 'BRL' }})<input id="extra" type="number" name="extra" step="0.01" min="0" value="{{ old('extra',0) }}">
</label>@if($company->enabled('loyalty'))<label>Cupom<input name="coupon" value="{{ old('coupon') }}" placeholder="Código do cupom">
</label>
<small class="muted">O cupom será validado ao concluir.</small>@endif<div class="metric-row total">
<span>Total estimado</span>
<strong id="sale-total">R$ 0,00</strong>
</div>
<div class="metric-row">
<span>Valor em fiado</span>
<strong id="sale-pending">R$ 0,00</strong>
</div>
<label>Observação<textarea name="notes" rows="3">{{ old('notes') }}</textarea>
</label>
<button class="full-width">Concluir venda →</button>
<small class="muted">Vendedor: {{ auth()->user()->name }}</small>
</section>
</aside>
</div>
</form>@endsection
