@extends('layout',['public'=>true])
@section('title',$company->name)
@section('content')<div class="page-heading">
<div>
<div class="eyebrow">CATÁLOGO</div>
@if($company->logo)<img src="{{ url('/logo/'.$company->id) }}" alt="{{ $company->name }}" width="90">@endif
<h1>{{ $company->name }}</h1>
<p>{{ $company->address }}</p>
</div>@if($company->whatsapp)<a class="button" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/\D/','',$company->whatsapp) }}">Fale com a gente</a>@endif</div>
<div class="catalog-grid">@forelse($products as $p)<article class="card">@if($p->image)<img src="{{ url('/media/'.$company->id.'/'.$p->id) }}" alt="{{ $p->name }}" loading="lazy">@endif<span class="eyebrow">{{ $p->category }}</span>
<h2>{{ $p->name }}</h2>
<p>{{ $p->description }}</p>
@foreach(json_decode($p->addons ?? '[]',true) as $addon)<small>{{ $addon['name'] }} + {{ \App\Services\Tenant::money($addon['price']) }}</small><br>@endforeach
<div class="metric-row">
<strong>{{ \App\Services\Tenant::money($p->price) }}</strong>
<span class="badge">{{ $company->enabled('stock') && $p->type==='product' && $p->stock<=0 ? 'Indisponível' : 'Disponível' }}</span>
</div>
</article>@empty<div class="card empty">O catálogo está sendo preparado.</div>@endforelse</div>{{ $products->links() }}@endsection
