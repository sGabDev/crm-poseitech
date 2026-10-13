@extends('layout',['public'=>true])
@section('title','Pagamento do pedido #'.$order->id)
@section('body-class','catalog-page')
@section('immersive',true)
@section('content')
<link rel="stylesheet" href="{{ asset('assets/catalog-storefront.css') }}?v={{ filemtime(public_path('assets/catalog-storefront.css')) }}">
<script src="{{ asset('assets/catalog-payment.js') }}?v={{ filemtime(public_path('assets/catalog-payment.js')) }}" defer></script>
<main class="catalog-payment" data-company="{{ $company->id }}" data-payment-status="{{ $statusUrl ?? url('/catalog/'.$company->slug.'/orders/'.$order->id.'/status') }}" data-status="{{ $checkout->status ?? 'pending' }}"><a href="{{ url('/catalog/'.$company->slug) }}#catalog-history">← Voltar à loja</a><h1>Pedido #{{ $order->id }}</h1><p>{{ $company->name }}</p><strong class="pix-amount">{{ \App\Services\Tenant::money($order->total) }}</strong>
@if($errors->any())<p class="store-notice error">{{ $errors->first() }}</p>@endif
@if($checkout?->mode==='test')<p class="store-notice">AMBIENTE DE TESTE · Não confirma pagamento real.</p>@endif
<p role="status" id="pix-status">{{ ['approved'=>'Pagamento confirmado! A loja recebeu seu pedido.','test_approved'=>'Aprovado em teste · sem baixa financeira.','refunded'=>'Pagamento reembolsado.','charged_back'=>'Pagamento contestado.','cancelled'=>'Pix cancelado.','rejected'=>'Pix recusado.','review'=>'Pagamento em conferência.'][$checkout->status ?? 'pending'] ?? 'Aguardando pagamento. Escaneie o QR code ou copie o código Pix.' }}</p>
@if($checkout && in_array($checkout->status,['creating','unknown','pending']) && now()->lt($checkout->expires_at))<section id="pix-instructions">@if($checkout->pix_image)<img class="pix-qr" src="data:image/png;base64,{{ $checkout->pix_image }}" alt="QR code para pagamento Pix">@endif @if($checkout->pix_code)<label>Pix copia e cola<textarea id="pix-code" readonly rows="3">{{ $checkout->pix_code }}</textarea></label><button type="button" id="copy-pix">Copiar código Pix</button><p class="muted">Válido até {{ \Carbon\Carbon::parse($checkout->expires_at)->timezone($company->timezone)->format('d/m H:i') }}. Confira o recebedor no aplicativo do seu banco.</p>@else<p>Estamos aguardando a geração do Pix. Seu pedido já foi salvo.</p><form method="post" action="{{ url('/catalog/'.$company->slug.'/orders/'.$order->id.'/pix') }}">@csrf<button>Consultar geração do Pix</button></form>@endif</section>@endif
<a class="button secondary" href="{{ url('/catalog/'.$company->slug) }}#catalog-history">Acompanhar pedido</a></main>
@endsection
