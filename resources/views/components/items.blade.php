<div class="table-wrap">
<table>
<thead>
<tr>
<th>Produto / serviço</th>
<th>Quantidade</th>
<th>Unitário</th>
<th>Subtotal</th>
</tr>
</thead>
<tbody>@foreach($items as $item)<tr>
<td>{{ $item->name }} @if($item->addons)<small>+ {{ $item->addons }}</small>@endif</td>
<td>{{ $item->quantity }}</td>
<td>{{ \App\Services\Tenant::money($item->price) }}</td>
<td>{{ \App\Services\Tenant::money($item->total) }}</td>
</tr>@endforeach</tbody>
</table>
</div>
