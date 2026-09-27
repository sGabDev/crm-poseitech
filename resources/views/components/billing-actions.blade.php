<details class="card no-print">
<summary>Lembretes de cobrança por e-mail</summary>
<p class="muted">Selecione uma conta a receber. O lembrete será enviado ao e-mail cadastrado do cliente.</p>@foreach($accounts as $a)@if($a->status==='pending' && $a->type==='receivable' && $a->customer_id)<div class="metric-row">
<span>{{ $a->description }} · {{ \App\Services\Tenant::money($a->amount-$a->paid) }}</span>
<form method="post" action="{{ url('/accounts/'.$a->id.'/email') }}">@csrf<button class="secondary">Enviar lembrete</button>
</form>
</div>@endif @endforeach</details>
