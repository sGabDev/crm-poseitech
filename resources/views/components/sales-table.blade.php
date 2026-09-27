<div class="table-wrap">
<table>
<thead>
<tr>
<th>Venda</th>
<th>Data</th>
<th>Total</th>
<th>Forma de pagamento</th>
<th>Status</th>
</tr>
</thead>
<tbody>@forelse($sales as $sale)<tr>
<td>
<a href="{{ url('/sales/'.$sale->id) }}">#{{ $sale->id }}</a>
</td>
<td>{{ \Carbon\Carbon::parse($sale->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</td>
<td>{{ \App\Services\Tenant::money($sale->total) }}</td>
<td>{{ \App\Services\Commerce::paymentLabel($sale) }}</td>
<td>
<span class="badge {{ $sale->status==='cancelled' ? 'danger' : '' }}">{{ $sale->status==='cancelled' ? 'Cancelada' : ($sale->fiado_amount>0 ? 'Fiado' : 'Pago') }}</span>
</td>
</tr>@empty<tr>
<td class="empty" colspan="5">Nenhuma venda encontrada no período.</td>
</tr>@endforelse</tbody>
</table>
</div>
