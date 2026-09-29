@extends('layout')
@section('title', 'Nova venda')
@section('content')
    <div class="page-heading">
        <div>
            <a href="{{ url('/sales') }}">← Vendas</a>
            <h1>Nova venda</h1>
            <p>Selecione os itens e informe os valores recebidos.</p>
        </div>
    </div>
    <div class="filters"><label>Tipo de operação<select id="operation-type"><option value="sale">Venda de produtos ou serviços</option><option value="deposit" @selected(old('operation')==='deposit')>Depósito na conta do cliente (não é venda)</option></select></label></div>
    @include('components.deposit-form')
    <form method="post" action="{{ url('/sales') }}" id="sale-form" data-coupon-url="{{ url('/coupons/preview') }}" data-stock="{{ $company->enabled('stock') ? 1 : 0 }}" data-currency="{{ $company->currency }}">@csrf<input
            type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="catalog_order_id" value="{{ old('catalog_order_id') }}">@if(old('catalog_order_id'))<p class="notice">Pedido online #{{ old('catalog_order_id') }}: confira o cliente, os valores atuais e o pagamento antes de concluir.</p>@endif<div class="grid wide-left">
            <div>
                <section class="card">
                    <h2>Cliente e itens</h2>
                    <label>Cliente
                        <div class="search-picker"><input type="search" class="search-input" placeholder="Digite nome, telefone ou CPF" autocomplete="off" aria-label="Nome, telefone ou CPF"><div class="search-results" hidden></div></div>
                        <select name="customer_id" data-search-select="Nome, telefone ou CPF" hidden>
                            <option value="">Consumidor não identificado</option>@foreach($customers as $c)
                                <option data-wallet="{{ $wallets[$c->id] ?? 0 }}" data-search="{{ $c->name }} {{ $c->phone }} {{ $c->document }}" value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->name }}
                            {{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach
                        </select>
                    </label>
                    <input type="hidden" name="allow_negative_stock" id="allow-negative-stock" value="0"><div class="form-grid"><label>Leitor de barras<input id="barcode" autocomplete="off" placeholder="Leia o código e pressione Enter"></label><label>Quantidade por leitura<input id="barcode-quantity" type="number" min="1" max="10000" value="1"></label></div><p id="barcode-message" role="status"></p><div id="sale-items">@foreach(old('items', [['product_id' => '', 'quantity' => 1]]) as $index => $line)
                        <div class="sale-line">
                            <label class="catalog-item-label">Produto ou serviço
                                <div class="search-picker"><input type="search" class="search-input" placeholder="Digite o nome do produto ou serviço" autocomplete="off" aria-label="Nome do produto ou serviço" required><div class="search-results" hidden></div></div>
                                <select name="items[{{ $index }}][product_id]" class="product-select" data-search-select="Nome do produto ou serviço" hidden>
                                    <option value="">Selecione...</option>@foreach($products as $p)
                                        <option value="{{ $p->id }}" data-code="{{ $p->code }}" data-stock="{{ $p->stock }}" data-type="{{ $p->type }}" data-price="{{ $p->price }}"
                                            data-addons="{{ $p->addons ?? '[]' }}" @selected(($line['product_id'] ?? '') == $p->id)>
                                    {{ $p->name }} · {{ \App\Services\Tenant::money($p->price) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="check"><input type="checkbox" class="custom-toggle" @checked(!empty($line['name']) && empty($line['product_id']))>Item avulso</label>
<label class="custom-field" hidden>Nome do item<input class="custom-name" name="items[{{ $index }}][name]" value="{{ $line['name'] ?? '' }}" maxlength="160"></label>
<label class="custom-field" hidden>Valor unitário<input class="custom-price" type="number" name="items[{{ $index }}][price]" value="{{ $line['price'] ?? '' }}" step="0.01" min="0.01" max="9999999"></label>
<label>Quantidade<input class="quantity" type="number" name="items[{{ $index }}][quantity]"
                                    min="1" max="10000" value="{{ $line['quantity'] }}" required>
                            </label>
                            <label class="addon-label">Adicionais<select multiple class="addon-select"
                                    name="items[{{ $index }}][addons][]"
                                    data-selected="{{ json_encode($line['addons'] ?? []) }}">
                                </select>
                            </label>
                            <button type="button" class="icon-button remove-line" aria-label="Remover item">×</button>
                    </div>@endforeach
                    </div>
                    <button type="button" class="secondary" id="add-item">+ Adicionar item</button>
                </section>
                <section class="card">
                    <h2>Pagamento</h2>
                    <label class="check"><input type="checkbox" name="use_balance" id="use-balance" value="1" @checked(old('use_balance',true))>Usar saldo disponível na conta do cliente</label>
                    <p class="muted">O valor é preenchido automaticamente, mas você pode editá-lo. Para voltar ao cálculo automático, clique em “Preencher com o total”. O restante não pago fica em fiado.</p>
                    <input type="hidden" name="auto_payment" id="auto-payment" value="{{ old('auto_payment',1) }}">
                    <div id="sale-payments">@foreach(old('payments', [['method' => 'pix', 'amount' => 0]]) as $index => $payment)
                        <div class="payment-line">
                            <label>Forma<select
                                    name="payments[{{ $index }}][method]">@foreach(config('poseitech.sale_methods') as $k => $v)
                                    <option value="{{ $k }}" @selected($payment['method'] === $k)>{{ $v }}</option>@endforeach
                                </select>
                            </label>
                            <label>Valor ({{ $company->currency ?? 'BRL' }})<input type="number" class="payment-amount"
                                    name="payments[{{ $index }}][amount]" step="0.01" min="0"
                                    value="{{ $payment['amount'] }}" required>
                            </label>
                            <button type="button" class="icon-button remove-line" aria-label="Remover pagamento">×</button>
                    </div>@endforeach
                    </div>
                    <button type="button" id="add-payment" class="secondary">+ Outra forma de pagamento</button>
                    <button type="button" id="reset-payment" class="secondary">Preencher com o total</button>
                </section>
                @if($company->enabled('orders'))
                    <section class="card">
                        <h2>Pedido</h2>
                        <label class="check">
                            <input type="checkbox" name="order" value="1" @checked(old('order'))>Criar pedido no
                            Kanban</label>@if($company->enabled('delivery'))<label class="check">
                                    <input type="checkbox" name="delivery" value="1" @checked(old('delivery'))>Entrega em
                                    endereço</label>
                                <div class="form-grid">
                                    <label>Endereço<input name="address" value="{{ old('address') }}">
                                    </label>
                                    <label>Região<input name="region" value="{{ old('region') }}">
                                    </label>
                                    <label>Taxa de entrega ({{ $company->currency ?? 'BRL' }})<input id="delivery-fee" type="number"
                                            name="fee" step="0.01" min="0" value="{{ old('fee', 0) }}">
                                    </label>
                            </div>@endif
                </section>@endif
            </div>
            <aside>
                <section class="card sale-summary">
                    <h2>Resumo da venda</h2>
                    <div class="metric-row">
                        <span>Subtotal</span>
                        <strong id="subtotal">R$ 0,00</strong>
                    </div>
                    <label>Desconto ({{ $company->currency ?? 'BRL' }})<input id="discount" type="text" name="discount"
                            value="{{ old('discount', 0) }}" placeholder="10,00 ou 10%">
                    </label>
                    <label>Acréscimo ({{ $company->currency ?? 'BRL' }})<input id="extra" type="text" name="extra"
                            value="{{ old('extra', 0) }}" placeholder="10,00 ou 10%">
                    </label><small class="muted">Digite 10,00 para um valor em dinheiro ou 10% para um percentual do subtotal.</small>@if($company->enabled('loyalty'))<label>Cupom<input name="coupon" value="{{ old('coupon') }}"
                                placeholder="Código do cupom">
                        </label>
                    <p id="coupon-feedback" class="notice" role="status" hidden></p><div id="coupon-summary" class="metric-row" hidden><span>Benefício do cupom</span><strong id="coupon-discount"></strong></div>@endif<div class="metric-row total">
                        <span>Total estimado</span>
                        <strong id="sale-total">R$ 0,00</strong>
                    </div>
                    <div class="metric-row" id="pending-row" hidden>
                        <span>Valor em fiado</span>
                        <strong id="sale-pending">R$ 0,00</strong>
                    </div>
                    <div class="metric-row" id="wallet-current-row" hidden><span>Saldo atual da conta</span><strong id="wallet-current"></strong></div><div class="metric-row" id="wallet-row" hidden><span>Valor restante na conta</span><strong id="wallet-remaining"></strong></div>
                    <label>Observação<textarea name="notes" rows="3">{{ old('notes') }}</textarea>
                    </label>
                    @if(!$registerOpen)<p class="notice error">Abra o caixa antes de concluir uma venda, qualquer que seja a forma de pagamento.</p>@if(auth()->user()->allows('cash',true))<a class="button secondary" href="{{ url('/cash') }}">Abrir caixa</a>@else<p>Peça ao responsável pelo caixa para abri-lo.</p>@endif
                    @endif
                    <button class="full-width" @disabled(!$registerOpen)>Concluir venda →</button>
                    <small class="muted">Vendedor: {{ auth()->user()->name }}</small>
                </section>
            </aside>
        </div>
</form>@endsection
