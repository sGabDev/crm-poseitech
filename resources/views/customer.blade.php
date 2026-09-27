@extends('layout')
@section('title','Perfil do cliente')
@section('content')<div class="page-heading">
<div>
<a href="{{ url('/records/customers') }}">← Clientes</a>
<h1>{{ $customer->name }}</h1>
<p>{{ $customer->email }} · {{ $customer->phone }}</p>
</div>
<a class="button secondary" href="{{ url('/records/customers/'.$customer->id.'/edit') }}">Editar cadastro</a>
</div>
<div class="actions">@if($stats->count>=2)<span class="badge">Recorrente</span>@else<span class="badge">Novo</span>@endif @if($stats->total>=100000)<span class="badge">VIP</span>@endif @if($stats->last && \Carbon\Carbon::parse($stats->last)->lt(now()->subDays(30)))<span class="badge danger">Inativo</span>@endif @if($accounts->where('status','pending')->where('due_date','<',now()->toDateString())->isNotEmpty())<span class="badge danger">Inadimplente</span>@endif</div>
<br>
<div class="kpi-grid">
<div class="kpi">
<span>Total comprado</span>
<strong>{{ \App\Services\Tenant::money($stats->total) }}</strong>
<small>{{ $stats->count }} compras concluídas</small>
</div>
<div class="kpi">
<span>Ticket médio</span>
<strong>{{ \App\Services\Tenant::money($stats->ticket) }}</strong>
<small>Maior compra: {{ \App\Services\Tenant::money($stats->largest) }}</small>
</div>
<div class="kpi">
<span>Primeira compra</span>
<strong>{{ $stats->first ? \Carbon\Carbon::parse($stats->first)->format('d/m/Y') : '—' }}</strong>
<small>Última: {{ $stats->last ? \Carbon\Carbon::parse($stats->last)->format('d/m/Y') : '—' }}</small>
</div>
<div class="kpi">
<span>Saldo devedor</span>
<strong>{{ \App\Services\Tenant::money($accounts->where('status','pending')->sum(fn($a)=>$a->amount-$a->paid)) }}</strong>
<small>Frequência média: {{ $stats->count>1 ? round(\Carbon\Carbon::parse($stats->first)->diffInDays(\Carbon\Carbon::parse($stats->last))/($stats->count-1)).' dias' : 'sem base suficiente' }}</small>
</div>
</div>
@if(session('portal_link'))<div class="notice success">Link válido por 30 dias: <a href="{{ session('portal_link') }}" target="_blank">{{ session('portal_link') }}</a>
<p>Compartilhe este link somente com o titular. Ele dá acesso ao histórico do cliente.</p>
</div>@endif
<div class="grid">
<section class="card">
<h2>Dados e relacionamento</h2><p>Vencimento mensal: dia {{ $customer->due_day }}.</p>@foreach(['document'=>'CPF/CNPJ','address'=>'Endereço','district'=>'Bairro','city'=>'Cidade','birthday'=>'Nascimento','tags'=>'Tags','source'=>'Origem','notes'=>'Observações'] as $k=>$v)<dl class="list-detail">
<dt>{{ $v }}</dt>
<dd>{{ $customer->$k ?? '—' }}</dd>
</dl>@endforeach<p>Mensagens por e-mail: {{ $customer->email_consent ? 'Autorizado' : 'Não autorizado' }}<br>Mensagens por WhatsApp: {{ $customer->whatsapp_consent ? 'Autorizado' : 'Não autorizado' }}</p>@if($customer->whatsapp && $customer->whatsapp_consent)<a class="button secondary" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/\D/','',$customer->whatsapp) }}">Abrir WhatsApp</a>@endif
@if($company->enabled('portal'))<div class="actions separated">
<form method="post" action="{{ url('/customers/'.$customer->id.'/portal') }}">@csrf<button>Gerar link do portal</button>
</form>
<form method="post" action="{{ url('/customers/'.$customer->id.'/portal') }}">@csrf<input name="action" type="hidden" value="revoke">
<button class="secondary">Revogar acesso</button>
</form>
</div>@endif</section>
<section class="card">
<h2>Produtos mais comprados</h2>@forelse($products as $p)<div class="metric-row">
<span>{{ $p->name }}</span>
<strong>{{ $p->quantity }} un.</strong>
</div>@empty<p class="muted">Nenhuma compra registrada.</p>@endforelse<h2 class="separated">Fidelidade</h2>
<p>{{ $points }} pontos / benefícios disponíveis</p>@if($company->enabled('loyalty'))<form class="filters" method="post" action="{{ url('/customers/'.$customer->id.'/redeem') }}">@csrf<label>Pontos a resgatar<input type="number" min="1" name="points" required>
</label>
<label>Benefício<input name="description" required>
</label>
<button>Resgatar</button>
</form>@endif</section>
</div>
<section class="card">
<h2>Histórico de compras</h2>@include('components.period',['paymentFilter'=>true])@include('components.sales-table'){{ $sales->links() }}</section>

<section class="card">
<h2>Pagamentos realizados</h2>@foreach($payments as $p)<div class="metric-row">
<span>{{ $p->created_at }} · {{ config('poseitech.payment_labels.'.$p->method) }}</span>
<strong>{{ \App\Services\Tenant::money($p->amount) }}</strong>
</div>@endforeach</section>
<div class="grid">
<section class="card">
<h2>Comunicações</h2>@forelse($emails as $mail)<div class="metric-row">
<span>{{ $mail->subject }}</span>
<span>{{ $mail->status }}</span>
</div>@empty<p class="muted">Nenhuma comunicação registrada.</p>@endforelse</section>

</div>

@include('components.credits')
@include('components.customer-extra')
@endsection
