<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>Comprovante #{{ $sale->id }}</title></head>
<body style="margin:0;padding:0;background:#edf3f5;color:#16343b;font-family:Arial,Helvetica,sans-serif">
@php($money=fn($amount)=>($company->currency==='BRL'?'R$ ':$company->currency.' ').number_format($amount/100,2,',','.'))
<div style="display:none;max-height:0;overflow:hidden">Sua compra #{{ $sale->id }} · {{ $money($sale->total) }} · Confira seu comprovante.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden">
<tr><td style="background:#11343d;color:#ffffff;padding:32px">
<p style="margin:0 0 18px;font-size:12px;letter-spacing:2px;color:#86ddd8">COMPROVANTE DIGITAL</p><h1 style="margin:0;font-size:28px;line-height:1.3">{{ $company->name }}</h1>
<p style="margin:14px 0 0;color:#d1e7e9;font-size:14px">Compra #{{ $sale->id }} · {{ \Carbon\Carbon::parse($sale->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</p>
</td></tr>
<tr><td style="padding:30px 32px 16px"><h2 style="margin:0 0 12px;font-size:23px">Olá, {{ $customer->name }}!</h2><p style="font-size:15px;line-height:1.7;margin:0">{{ $sale->status==='cancelled'?'Esta compra foi cancelada. Consulte abaixo os registros e estornos.':'Sua compra foi registrada. Reunimos os detalhes para você consultar sempre que precisar.' }}</p></td></tr>
<tr><td style="padding:12px 32px"><table role="presentation" width="100%" style="background:#eaf7f3;border-radius:12px"><tr><td style="padding:22px"><span style="font-size:12px;color:#326c61">TOTAL DA COMPRA</span><p style="margin:8px 0 0;font-size:34px;font-weight:bold;color:#087e70">{{ $money($sale->total) }}</p></td></tr></table></td></tr>
<tr><td style="padding:20px 32px"><h2 style="font-size:18px;margin:0 0 14px">Seus itens</h2>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px"><thead><tr><th align="left" style="padding:10px 0;border-bottom:1px solid #dbe7ea">Item</th><th align="right" style="padding:10px 0;border-bottom:1px solid #dbe7ea">Valor</th></tr></thead><tbody>
@foreach($items as $item)<tr><td style="padding:14px 8px 14px 0;border-bottom:1px solid #edf1f3">{{ $item->quantity }} × {{ $item->name }}@if($item->addons)<br><small style="color:#637980">{{ $item->addons }}</small>@endif</td><td align="right" style="padding:14px 0;border-bottom:1px solid #edf1f3;white-space:nowrap">{{ $money($item->total) }}</td></tr>@endforeach
<tr><td style="padding-top:14px">Subtotal</td><td align="right" style="padding-top:14px">{{ $money($sale->subtotal) }}</td></tr>
@if($sale->discount)<tr><td style="padding-top:10px">Desconto</td><td align="right">− {{ $money($sale->discount) }}</td></tr>@endif
@if($sale->extra)<tr><td style="padding-top:10px">Acréscimos</td><td align="right">{{ $money($sale->extra) }}</td></tr>@endif
</tbody></table></td></tr>
<tr><td style="padding:8px 32px 22px"><h2 style="font-size:18px;margin:0 0 12px">Pagamento</h2>
@if($sale->wallet_used)<p style="font-size:14px">Saldo da conta{{ $sale->status==='cancelled'?' · devolvido':'' }}: <strong>{{ $money($sale->wallet_used) }}</strong></p>@endif
@foreach($payments as $payment)<p style="font-size:14px">{{ config('poseitech.payment_labels.'.$payment->method,$payment->method) }}{{ $payment->reversed_at?' · estornado':'' }}: <strong>{{ $money($payment->amount) }}</strong></p>@endforeach
@if($sale->status!=='cancelled' && $sale->paid<$sale->total)<p style="padding:14px;background:#fff4dd;color:#76551d;border-radius:8px;font-size:14px">Valor em fiado desta compra: <strong>{{ $money($sale->total-$sale->paid) }}</strong></p>@endif
<p style="margin:24px 0 16px"><a href="{{ url('/receipt/'.$sale->receipt_hash) }}" style="display:inline-block;padding:16px 24px;background:#087e8b;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;border-radius:8px">Abrir comprovante completo →</a></p>
@if($company->enabled('portal') && ($portalUrl=\App\Services\PortalLink::url($customer)))<p><a href="{{ $portalUrl }}" style="font-size:14px;color:#087e8b;text-decoration:underline">Acompanhar minhas compras no portal</a></p>@endif
<p style="font-size:12px;line-height:1.6;color:#71848a">Você pode imprimir ou salvar o comprovante em PDF ao abri-lo. Os valores acima refletem o momento do envio; consulte o comprovante para atualizações.</p></td></tr>
<tr><td style="background:#f5f8f9;padding:22px 32px;font-size:12px;line-height:1.7;color:#667e85"><strong>{{ $company->name }}</strong>@if($company->address)<br>{{ $company->address }}@endif<br>Comprovante comercial. Não substitui documento fiscal.<br>Mensagem referente à sua compra, enviada automaticamente.</td></tr>
</table></td></tr></table></body></html>
