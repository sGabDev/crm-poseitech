{{ $company->name }} — Compra #{{ $sale->id }}
Olá, {{ $customer->name }}.
Status: {{ $sale->status==='cancelled'?'Cancelada':'Registrada' }}
Total: {{ $company->currency }} {{ number_format($sale->total/100,2,',','.') }}
@foreach($items as $item)
{{ $item->quantity }} × {{ $item->name }} — {{ number_format($item->total/100,2,',','.') }}
@endforeach
@if($sale->wallet_used)
Saldo da conta utilizado: {{ number_format($sale->wallet_used/100,2,',','.') }}
@endif
Comprovante completo: {{ url('/receipt/'.$sale->receipt_hash) }}
Comprovante comercial. Não substitui documento fiscal.
