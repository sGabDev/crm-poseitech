@extends('layout')
@section('title','Oportunidades')
@section('content')<div class="page-heading">
<div>
<div class="eyebrow">DADOS QUE VIRAM AÇÃO</div>
<h1>Oportunidades</h1>
<p>Encontre formas de vender mais e cuidar dos seus clientes.</p>
</div>
</div>
<div class="grid">@foreach($cards as $card)<article class="card">
<span class="eyebrow">INSIGHT DO SEU NEGÓCIO</span>
<h2>{{ $card['title'] }}</h2>
<p class="muted">{{ $card['body'] }}</p>
<a class="button secondary" href="{{ url($card['url']) }}">{{ $card['action'] }} →</a>
</article>@endforeach</div>@endsection
