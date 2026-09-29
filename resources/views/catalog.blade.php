@extends('layout',['public'=>true])
@section('title',$company->name)
@section('content')
@php($checkout = ($company->settings['catalog_checkout'] ?? false) && $company->enabled('orders'))
@php($color = $company->settings['catalog_color'] ?? '#155e75')
<link rel="stylesheet" href="{{ asset('assets/catalog.css') }}?v={{ filemtime(public_path('assets/catalog.css')) }}">
<script src="{{ asset('assets/catalog.js') }}?v={{ filemtime(public_path('assets/catalog.js')) }}" defer></script>
<div class="catalog-store" style="--catalog-color:{{ preg_match('/^#[a-fA-F0-9]{6}$/',$color) ? $color : '#155e75' }}" data-company="{{ $company->id }}" data-submitted="{{ session('catalog_submitted') ? 1 : 0 }}" data-currency="{{ $company->currency }}">
    <nav class="catalog-topnav" aria-label="Navegação da loja"><a href="#catalog-menu" class="catalog-wordmark">{{ $company->name }}</a><div><a href="#catalog-history">Meus pedidos</a>@if($company->whatsapp)<a href="https://wa.me/{{ preg_replace('/\D/','',$company->whatsapp) }}" target="_blank" rel="noopener">Contato ↗</a>@endif</div></nav>
    <header class="catalog-hero">
        <div class="catalog-cover" aria-hidden="true"><span>Feito para o seu dia.</span></div>
        <div class="catalog-store-info">
            <div class="catalog-logo">@if($company->logo)<img src="{{ url('/logo/'.$company->id) }}" alt="Logo {{ $company->name }}">@else<span aria-hidden="true">{{ mb_substr($company->name,0,1) }}</span>@endif</div>
            <div class="catalog-store-heading"><span class="catalog-eyebrow">CATÁLOGO ONLINE</span><h1>{{ $company->name }}</h1><p>{{ ($company->settings['catalog_title'] ?? '') ?: 'Escolha seus favoritos. Nós cuidamos do resto.' }}</p></div>
            <a class="catalog-store-link" href="#catalog-history">Acompanhar pedido →</a>
        </div>
        <div class="catalog-store-description"><p>{{ ($company->settings['catalog_description'] ?? '') ?: 'Confira os produtos e serviços disponíveis e monte seu pedido.' }}</p><div class="catalog-store-meta">@if($company->address)<span>⌖ {{ $company->address }}</span>@endif<span>Entrega e pagamento a combinar</span></div></div>
    </header>
    <section id="catalog-menu" class="catalog-menu" aria-label="Catálogo de produtos">
        <div class="catalog-toolbar"><label class="catalog-search-label"><span>Buscar no catálogo</span><input id="catalog-search" type="search" placeholder="O que você procura hoje?"></label><label class="catalog-category-label">Categoria<select id="catalog-category"><option value="">Todas as categorias</option>@foreach($products->pluck('category')->filter()->unique() as $category)<option>{{ $category }}</option>@endforeach</select></label><label>Ordenar<select id="catalog-sort"><option value="name">Nome</option><option value="price-low">Menor preço</option><option value="price-high">Maior preço</option></select></label></div>
        <nav class="catalog-categories" aria-label="Categorias"><button type="button" data-category-filter="" aria-pressed="true">Todos</button>@foreach($products->pluck('category')->filter()->unique() as $category)<button type="button" data-category-filter="{{ $category }}" aria-pressed="false">{{ $category }}</button>@endforeach</nav>
        <div class="catalog-layout">
            <div class="catalog-menu-main"><div class="catalog-section-title"><div><span class="catalog-eyebrow">ESCOLHA SEUS FAVORITOS</span><h2 id="catalog-section-heading">Nosso catálogo</h2></div><span id="catalog-count" role="status"></span></div>
                <section class="catalog-products" aria-label="Produtos e serviços">
                @forelse($products as $p)
                    @php($unavailable = $company->enabled('stock') && $p->type === 'product' && $p->stock <= 0)
                    @php($addons = json_decode($p->addons ?? '[]',true))
                    <article class="catalog-product" data-id="{{ $p->id }}" data-name="{{ $p->name }}" data-category="{{ $p->category }}" data-price="{{ $p->price }}">
                        <div class="catalog-product-content"><span class="catalog-tag">{{ $p->category ?: ($p->type === 'service' ? 'Serviço' : 'Produto') }}</span><h3>{{ $p->name }}</h3><p>{{ $p->description }}</p><strong class="catalog-price">{{ \App\Services\Tenant::money($p->price) }}</strong></div>
                        <div class="catalog-picture">@if($p->image)<img src="{{ url('/media/'.$company->id.'/'.$p->id) }}" alt="{{ $p->name }}" loading="lazy">@else<span aria-hidden="true">{{ $p->type === 'service' ? '✦' : '◇' }}</span>@endif</div>
                        <div class="catalog-product-actions">
                        @if($checkout && !$unavailable)
                            @if(count($addons))<details class="catalog-addon-options"><summary>Personalizar · {{ count($addons) }} adicionais</summary>@foreach($addons as $index=>$addon)<label class="check"><input type="checkbox" class="catalog-addon" value="{{ $index }}" data-price="{{ $addon['price'] }}">{{ $addon['name'] }} + {{ \App\Services\Tenant::money($addon['price']) }}</label>@endforeach</details>@endif
                            <div class="catalog-buy-row"><label>Na sacola<input class="catalog-quantity" type="number" value="0" min="0" max="{{ $company->enabled('stock') && $p->type === 'product' ? min(10000,$p->stock) : 10000 }}" aria-label="Quantidade de {{ $p->name }}"></label><button type="button" class="catalog-add" aria-label="Adicionar {{ $p->name }} à sacola">Adicionar <span aria-hidden="true">+</span></button></div>
                        @elseif($unavailable)<span class="badge">Indisponível no momento</span>@else<span class="muted">Consulte a loja para comprar</span>@endif
                        </div>
                    </article>
                @empty<div class="catalog-empty-state"><h3>Estamos preparando nossa vitrine</h3><p>Fale com a loja para conhecer as novidades.</p></div>@endforelse
                <div id="catalog-empty" class="catalog-empty-state" hidden><h3>Nenhum item encontrado</h3><p>Experimente outro nome ou categoria.</p></div>
                </section>
            </div>
            @if($checkout)<aside id="catalog-cart" aria-label="Sua sacola"><form method="post" action="{{ url('/catalog/'.$company->slug) }}" class="card" id="catalog-checkout" data-old-items="{{ json_encode(old('items',[])) }}">@csrf
                <input type="hidden" name="request_key" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
                <div class="catalog-cart-heading"><div><span class="catalog-eyebrow">SEU PEDIDO</span><h2>Minha sacola</h2></div><button type="button" class="secondary" id="catalog-clear">Limpar</button></div>
                <p id="catalog-cart-notice" role="status"></p><div id="catalog-cart-items" aria-live="polite">Sua sacola está vazia.</div>
                <div class="metric-row total"><span>Subtotal</span><strong id="catalog-total">{{ \App\Services\Tenant::money(0) }}</strong></div><p class="catalog-checkout-hint">Frete e pagamento a combinar com a loja.</p>
                <button type="button" class="full-width" id="catalog-continue" disabled>Continuar pedido →</button>
                <details class="catalog-customer-details" id="catalog-customer-details" @if($errors->any())open @endif><summary>Seus dados e entrega</summary>
                    <label>Seu nome<input name="name" required maxlength="160" autocomplete="name" value="{{ old('name') }}"></label>
                    <label>Telefone / WhatsApp<input name="phone" type="tel" data-phone required maxlength="30" autocomplete="tel" value="{{ old('phone') }}"></label>
                    <label>Endereço (para entrega)<input name="address" maxlength="255" autocomplete="street-address" value="{{ old('address') }}" placeholder="Rua, número e bairro"></label>
                    <label>Observações<textarea name="notes" maxlength="1000" rows="2" placeholder="Alguma informação para a loja?">{{ old('notes') }}</textarea></label>
                    <div id="catalog-inputs"></div><button class="full-width" id="catalog-send" disabled>Enviar pedido à loja</button><small>A loja confirmará disponibilidade, entrega e pagamento. Seus dados são usados para atender este pedido.</small>
                </details>
            </form></aside>@endif
        </div>
    </section>
    @include('components.catalog-history')
    @if($checkout)<a href="#catalog-cart" class="catalog-cart-bar"><span>Ver sacola</span><strong id="catalog-bar-total"></strong><span aria-hidden="true">→</span></a>@endif
</div>
@endsection
