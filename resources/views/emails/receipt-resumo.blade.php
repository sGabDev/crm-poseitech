<table width="100%" style="font-size:14px"><tr><td>Subtotal</td><td align="right">{{ \App\Services\Tenant::money($sale->subtotal) }}</td></tr>
@if($sale->discount)<tr><td>Desconto</td><td align="right">− {{ \App\Services\Tenant::money($sale->discount) }}</td></tr>@endif
@if($sale->extra)<tr><td>Acréscimos</td><td align="right">{{ \App\Services\Tenant::money($sale->extra) }}</td></tr>@endif</table>
