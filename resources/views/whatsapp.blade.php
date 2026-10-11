@extends('layout')
@section('title','WhatsApp · Bots')
@section('content')
<div class="page-heading"><div><h1>WhatsApp da empresa</h1><p>Conecte seus números e envie os comprovantes de compra automaticamente.</p></div></div>
<section class="card"><h2>Criar bot</h2><form method="post" action="{{ url('/whatsapp') }}" class="filters">@csrf<label>Nome do bot<input name="name" required maxlength="80" placeholder="Atendimento / Vendas"></label><button>Criar bot</button></form><p>Cada empresa tem suas próprias conexões. Escolha um bot para enviar os comprovantes. O e-mail continua funcionando de forma independente.</p></section>
@forelse($connections as $bot)
<section class="card" data-whatsapp-status="{{ url('/whatsapp/'.$bot->id.'/status') }}">
<h2>{{ $bot->name }} @if($bot->automatic)<span class="badge">Envio automático</span>@endif</h2>
<p role="status" data-state>Consultando conexão…</p><img data-qr hidden width="260" height="260" alt="QR code para conectar o WhatsApp"><p>Abra o WhatsApp no celular → Dispositivos conectados → Conectar dispositivo. A conexão será confirmada automaticamente nesta tela.</p>
<form method="post" action="{{ url('/whatsapp/'.$bot->id) }}" class="actions">@csrf
<button name="action" value="connect">Conectar / Gerar QR code</button>
<button name="action" value="{{ $bot->automatic ? 'disable' : 'automatic' }}" class="secondary">{{ $bot->automatic ? 'Desativar envio automático' : 'Usar para comprovantes' }}</button>
<button name="action" value="disconnect" class="danger-button">Desconectar</button>
</form></section>
@empty<section class="card">Crie seu primeiro bot para conectar um WhatsApp.</section>@endforelse
<section class="card"><h2>Histórico de comprovantes</h2><p>Envios pendentes são processados automaticamente. “Sem confirmação” exige conferir a conversa no celular.</p><div class="table-wrap"><table><thead><tr><th>Venda</th><th>Telefone</th><th>Status</th><th>Detalhes</th></tr></thead><tbody>
@forelse($messages as $message)<tr><td><a href="{{ url('/sales/'.$message->sale_id) }}">#{{ $message->sale_id }}</a></td><td>{{ $message->recipient }}</td><td>{{ ['pending'=>'Na fila','sending'=>'Enviando','sent'=>'Enviado','failed'=>'Falhou','unknown'=>'Sem confirmação','cancelled'=>'Cancelado'][$message->status] ?? $message->status }}</td><td>{{ $message->error ?? $message->sent_at }}</td></tr>@empty<tr><td colspan="4">Nenhum envio registrado.</td></tr>@endforelse
</tbody></table></div>{{ $messages->links() }}</section>
<script src="{{ asset('assets/whatsapp.js') }}?v={{ filemtime(public_path('assets/whatsapp.js')) }}" defer></script>
@endsection
