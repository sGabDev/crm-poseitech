<div class="table-wrap">
<table>
<thead>
<tr>
<th>Venda</th>
<th>Data</th>
<th>Total</th>
<th>Recebido</th>
<th>Pendente</th>
<th>Status</th>
</tr>
</thead>
<tbody>@forelse($sales as $sale)<tr>
<td>
<a href="{{ url('/sales/'.$sale->id) }}">#{{ $sale->id }}</a>
</td>
<td>{{ \Carbon\Carbon::parse($sale->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</td>
<td>{{ \App\Services\Tenant::money($sale->total) }}</td>
<td>{{ \App\Services\Tenant::money($sale->paid) }}</td>
<td>{{ \App\Services\Tenant::money($sale->status==='cancelled' ? 0 : $sale->total-$sale->paid) }}</td>
<td>
<span class="badge {{ $sale->status==='cancelled' ? 'danger' : '' }}">{{ $sale->status==='cancelled' ? 'Cancelada' : ($sale->paid===$sale->total ? 'Paga' : 'A receber') }}</span>
</td>
</tr>@empty<tr>
<td class="empty" colspan="6">Nenhuma venda encontrada no período.</td>
</tr>@endforelse</tbody>
</table>
</div>
