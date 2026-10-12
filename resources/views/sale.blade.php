@extends('layout')
@section('title','Detalhes da venda')
@section('content')<div class="page-heading">
<div>
<a href="{{ url('/sales') }}">← Vendas</a>
<h1>Venda #{{ $sale->id }}</h1>
<p>{{ $customer->name ?? 'Consumidor não identificado' }} · {{ \Carbon\Carbon::parse($sale->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</p>
</div>
<div class="actions">
<a class="button secondary" href="{{ url('/receipt/'.$sale->receipt_hash) }}" target="_blank" rel="noopener">Ver comprovante ↗</a>
@if(auth()->user()->allows('sales', true))<a class="button" href="{{ url('/sales/new') }}">+ Nova venda</a>@endif
</div>
</div>
@if(session('sale_completed') == $sale->id)
<section class="sale-completed" role="status">
<span class="completion-icon" aria-hidden="true">✓</span>
<div><h2>{{ $sale->status==='pending_payment' ? 'Venda registrada' : 'Venda concluída' }}</h2><p>O registro foi salvo. Confira o pagamento ou inicie o próximo atendimento.</p></div>
@if(auth()->user()->allows('sales', true))<a class="button" href="{{ url('/sales/new') }}">Fazer nova venda →</a>@endif
</section>
@endif
<div class="kpi-grid">
<div class="kpi">
<span>Total</span>
<strong>{{ \App\Services\Tenant::money($sale->total) }}</strong>
</div>
<div class="kpi">
<span>Forma de pagamento</span>
<strong>{{ \App\Services\Commerce::paymentLabel($sale) }}</strong>
</div>
<div class="kpi">
<span>Status</span>
<strong>{{ $sale->status==='cancelled' ? 'Cancelada' : ($sale->paid < $sale->total ? 'Aguardando pagamento' : 'Pago') }}</strong>
</div>
</div>
@if(in_array('mercadopago',json_decode($sale->payment_methods ?? '[]',true)) && $sale->status!=='cancelled')
<section class="card"><h2>Mercado Pago</h2><p class="muted">A confirmação do pagamento atualiza esta venda e a conta do cliente automaticamente.</p>
@foreach($accounts as $account)@if($account->status==='pending')<form method="post" action="{{ url('/accounts/'.$account->id.'/mercadopago') }}">@csrf<button>Gerar / abrir link de pagamento</button></form>@endif @endforeach
@foreach($mercadoPagoLinks ?? [] as $link)<p><a href="{{ url('/payments/mercadopago/'.$link->id) }}">Ver pagamento e envios #{{ $link->id }}</a> · {{ ['approved'=>'Confirmado','pending'=>'Aguardando pagamento','unknown'=>'Criação sem confirmação','review'=>'Conferir pagamento','test_approved'=>'Aprovado em teste'][$link->status] ?? $link->status }}</p>@endforeach
</section>@endif
<section class="card">@include('components.items')<p>Desconto: {{ \App\Services\Tenant::money($sale->discount) }} · Acréscimos: {{ \App\Services\Tenant::money($sale->extra) }}</p>
<p>{{ $sale->notes }}</p>
<div class="actions">
@if($company->enabled('whatsapp'))
<form method="post" action="{{ url('/sales/'.$sale->id.'/whatsapp') }}">@csrf<button class="secondary">Enviar WhatsApp</button></form>
<a class="button secondary" href="https://wa.me/?text={{ rawurlencode('Comprovante de compra: '.url('/receipt/'.$sale->receipt_hash)) }}" target="_blank" rel="noopener">Compartilhar por WhatsApp</a>
@endif
<form method="post" action="{{ url('/sales/'.$sale->id.'/email') }}">@csrf<button class="secondary">Enviar comprovante por e-mail</button>
</form>
@if($sale->customer_id && $company->enabled('message_templates') && auth()->user()->allows('message_templates',true))<a class="button secondary" href="{{ url('/message-templates?sale_id='.$sale->id.'&customer_id='.$sale->customer_id.'&purpose=receipt&channel=email') }}">Enviar com modelo</a>@endif
</div>
@if($whatsappMessage ?? null)
<p role="status">WhatsApp: {{ ['pending'=>'Na fila','sending'=>'Enviando','sent'=>'Enviado','failed'=>'Falhou','unknown'=>'Sem confirmação','cancelled'=>'Cancelado'][$whatsappMessage->status] ?? $whatsappMessage->status }}. {{ $whatsappMessage->error }}</p>
@endif
</section>
<section class="card">
<h2>Pagamentos</h2>@foreach($payments as $p)<div class="metric-row">
<span>{{ config('poseitech.payment_labels.'.$p->method) }} · {{ $p->created_at }} {{ $p->reversed_at ? '(estornado)' : '' }}</span>
<strong>{{ \App\Services\Tenant::money($p->amount) }}</strong>
</div>@endforeach</section>
@can('manage-company')@if($sale->status!=='cancelled')<details class="card">
<summary>Cancelar venda e estornar lançamentos</summary>
<form class="filters" method="post" action="{{ url('/sales/'.$sale->id.'/cancel') }}" data-confirm="Cancelar a venda, devolver pagamentos e repor o estoque?">@csrf<label>Motivo<input name="reason" required minlength="5" maxlength="500">
</label>
<button class="danger-button">Confirmar cancelamento</button>
</form>
</details>@endif
@endcan
@endsection
