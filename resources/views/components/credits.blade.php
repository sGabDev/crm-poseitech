<section class="card">
<h2>Créditos e cashback</h2>
<p>Saldo disponível: <strong>{{ \App\Services\Tenant::money($credits->sum('amount')) }}</strong>
</p>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Data</th>
<th>Descrição</th>
<th>Crédito / utilização</th>
</tr>
</thead>
<tbody>@forelse($credits as $entry)<tr>
<td>{{ $entry->created_at }}</td>
<td>{{ $entry->description }}</td>
<td>{{ \App\Services\Tenant::money($entry->amount) }}</td>
</tr>@empty<tr>
<td colspan="3" class="muted">Nenhum crédito lançado.</td>
</tr>@endforelse</tbody>
</table>
</div>
@if(!($readonly ?? false) && $company->enabled('loyalty'))
@can('manage-company')<details class="separated">
<summary>Registrar crédito / utilização de benefício</summary>
<form method="post" action="{{ url('/customers/'.$customer->id.'/credits') }}" class="filters">@csrf<label>Operação<select name="direction">
<option value="in">Conceder crédito</option>
<option value="out">Registrar utilização</option>
</select>
</label>
<label>Valor ({{ $company->currency ?? 'BRL' }})<input type="number" name="amount" min="0.01" step="0.01" required>
</label>
<label>Motivo / benefício<input name="description" required>
</label>
<button>Confirmar</button>
</form>
<p class="muted">Ao utilizar o benefício em uma venda, registre o mesmo valor como desconto.</p>
</details>@endcan
@endif</section>
