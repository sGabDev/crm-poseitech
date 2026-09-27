@extends('layout')
@section('title','Notificações')
@section('content')<div class="page-heading">
<div>
<h1>Notificações</h1>
<p>Avisos importantes da sua operação.</p>
</div>
</div>@forelse($alerts as $alert)<section class="card">
<div class="section-heading">
<h2>{{ $alert->title }}</h2>
<span class="badge">{{ $alert->read_at ? 'Lida' : 'Nova' }}</span>
</div>
<p>{{ $alert->body }}</p>
<div class="actions">@if($alert->url)<a href="{{ url($alert->url) }}">Ver detalhes →</a>@endif @if(!$alert->read_at)<form method="post" action="{{ url('/alerts/'.$alert->id) }}">@csrf<button class="secondary">Marcar como lida</button>
</form>@endif</div>
</section>@empty<div class="card empty">Nenhuma notificação no momento.</div>@endforelse{{ $alerts->links() }}@endsection
