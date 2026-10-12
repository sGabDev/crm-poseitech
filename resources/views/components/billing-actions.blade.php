<details class="card no-print">
<summary>Lembretes de cobrança</summary>
<p class="muted">Selecione uma conta a receber. O lembrete será enviado ao e-mail cadastrado do cliente.</p>@foreach($accounts as $a)@if($a->status==='pending' && $a->type==='receivable' && $a->customer_id)<div class="metric-row">
<span>{{ $a->description }} · {{ \App\Services\Tenant::money($a->amount-$a->paid) }}</span>
<form method="post" action="{{ url('/accounts/'.$a->id.'/email') }}">@csrf<button class="secondary">Enviar lembrete</button>
</form>
@if($company->enabled('message_templates') && auth()->user()->allows('message_templates',true))<a class="button secondary" href="{{ url('/message-templates?account_id='.$a->id.'&customer_id='.$a->customer_id.'&purpose=billing&channel=whatsapp') }}">Cobrar com modelo</a>@endif
@if($company->mercadopago['enabled'] ?? false)<form method="post" action="{{ url('/accounts/'.$a->id.'/mercadopago') }}">@csrf<button class="secondary">Link Mercado Pago</button></form>@endif
</div>@endif @endforeach</details>
