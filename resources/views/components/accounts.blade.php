<div class="table-wrap">
<table>
<thead>
<tr>
<th>Descrição</th>
<th>Vencimento</th>
<th>Valor</th>
<th>Saldo</th>
<th>Situação</th>@if(!($readonly ?? false))<th>Pagamento</th>@endif</tr>
</thead>
<tbody>@forelse($accounts as $a)<tr>
<td>{{ $a->description }}</td>
<td>{{ \Carbon\Carbon::parse($a->due_date)->format('d/m/Y') }}</td>
<td>{{ \App\Services\Tenant::money($a->amount) }}</td>
<td>{{ \App\Services\Tenant::money($a->status==='cancelled' ? 0 : $a->amount-$a->paid) }}</td>
<td>
<span class="badge {{ $a->status==='pending' && $a->due_date<now()->toDateString() ? 'danger' : '' }}">{{ $a->status==='paid' ? 'Pago' : ($a->status==='cancelled' ? 'Cancelado' : ($a->due_date<now()->toDateString() ? 'Vencido' : 'Pendente')) }}</span>
</td>@if(!($readonly ?? false))<td>@if($a->status==='pending' && auth()->user()->allows($a->origin==='credit' ? 'credit' : 'finance',true))<details>
<summary>Registrar</summary>
<form class="stack compact-form" method="post" action="{{ url('/accounts/'.$a->id.'/pay') }}">@csrf<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Valor ({{ $company->currency ?? 'BRL' }})<input type="number" name="amount" step="0.01" min="0.01" max="{{ ($a->amount-$a->paid)/100 }}" value="{{ number_format(($a->amount-$a->paid)/100,2,'.','') }}" required>
</label>
<label>Forma<select name="method">@foreach(config('poseitech.methods') as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
</label>
<button>Confirmar pagamento</button>
</form>
</details>@endif</td>@endif</tr>@empty<tr>
<td class="empty" colspan="6">Nenhuma conta encontrada.</td>
</tr>@endforelse</tbody>
</table>
</div>
