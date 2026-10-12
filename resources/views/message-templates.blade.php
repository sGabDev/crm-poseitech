@extends('layout')
@section('title','Modelos de mensagem')
@section('content')
<script src="{{ asset('assets/message-templates.js') }}?v={{ filemtime(public_path('assets/message-templates.js')) }}" defer></script>
<div class="page-heading"><div><h1>Modelos de mensagem</h1><p>Os textos atuais já estão prontos para editar. Escolha um modelo e personalize para sua empresa.</p></div><div class="actions">@if(auth()->user()->allows('message_templates',true))<a class="button" href="{{ url('/message-templates?new=1') }}">+ Novo modelo</a>@endif<a class="button secondary" href="{{ url('/settings') }}">Configurações</a></div></div>
<div class="grid">
<section class="card">
<h2>{{ $editing ? 'Editar modelo' : 'Criar modelo' }}</h2>
@if(auth()->user()->allows('message_templates',true))
<form class="stack" method="post" action="{{ url('/message-templates'.($editing ? '/'.$editing->id : '')) }}" data-template-editor>@csrf
<label>Nome do modelo<input name="name" maxlength="120" value="{{ old('name',$editing->name ?? '') }}" placeholder="Ex.: Lembrete amigável de cobrança" required></label>
<div class="form-grid"><label>Canal<select name="channel"><option value="email" @selected(old('channel',$editing->channel ?? '')==='email')>E-mail</option><option value="whatsapp" @selected(old('channel',$editing->channel ?? '')==='whatsapp')>WhatsApp</option></select></label>
<label>Finalidade<select name="purpose">@foreach(\App\Services\MessageTemplates::PURPOSES as $key=>$label)<option value="{{ $key }}" @selected(old('purpose',$editing->purpose ?? 'general')===$key)>{{ $label }}</option>@endforeach</select></label></div>
<label data-subject>Assunto do e-mail<input name="subject" maxlength="200" value="{{ old('subject',$editing->subject ?? '') }}" placeholder="Ex.: Lembrete de pagamento · {empresa}"></label>
<label>Mensagem<textarea name="body" rows="7" maxlength="7000" required placeholder="Olá, {nome}! Aqui é da {empresa}.">{{ old('body',$editing->body ?? '') }}</textarea></label>
<section data-receipt-html hidden>
<h3>HTML do comprovante</h3><p class="muted">Este é o modelo completo do CRM. Edite textos, cores e estrutura usando estilos inline. Os blocos de itens, resumo e pagamentos são preenchidos automaticamente. A mensagem acima é usada em {mensagem_html} e na versão em texto.</p>
<label>Código HTML<textarea name="html_body" rows="18" maxlength="60000" spellcheck="false" style="font-family:Consolas,monospace;font-size:12px;tab-size:2">{{ old('html_body',$editing->html_body ?? \App\Services\ReceiptHtml::defaults()) }}</textarea></label>
<div class="actions" style="margin-top:12px"><button type="button" class="secondary" data-restore-receipt>Restaurar HTML padrão do CRM</button></div>
<template data-default-receipt>{{ \App\Services\ReceiptHtml::defaults() }}</template>
</section>
<p class="muted">Clique para inserir uma variável:</p><div class="actions" data-variables></div>
<label class="check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default',$editing->is_default ?? false))>Usar como padrão desta finalidade e canal</label>
<p class="muted">Comprovantes usam o padrão ao finalizar vendas. Cobranças usam o padrão ao enviar lembretes. Mensagens livres são enviadas manualmente. Sem um padrão, permanece o texto do sistema.</p>
<div class="actions"><button>{{ $editing ? 'Salvar alterações' : 'Criar modelo' }}</button>@if($editing)<a class="button secondary" href="{{ url('/message-templates') }}">Cancelar edição</a>@endif</div>
</form>
@else<p class="muted">Você tem acesso de leitura aos modelos.</p>@endif
</section>
<section class="card"><h2>Prévia da mensagem</h2><p class="muted">Exemplo com dados ilustrativos. O envio usa os dados do cliente selecionado.</p><strong data-preview-subject></strong><iframe data-html-preview title="Prévia do comprovante por e-mail" sandbox="" referrerpolicy="no-referrer" hidden style="width:100%;height:720px;border:1px solid var(--line);border-radius:8px;margin-top:16px;background:white"></iframe><p data-template-preview style="white-space:pre-wrap;margin-top:16px;overflow-wrap:anywhere"></p><hr style="border:0;border-top:1px solid var(--line)"><h2>Seus modelos</h2>
@forelse($templates as $template)<div class="metric-row"><div><strong>{{ $template->name }}</strong><br><small>{{ $template->channel==='email' ? 'E-mail' : 'WhatsApp' }} · {{ \App\Services\MessageTemplates::PURPOSES[$template->purpose] }}{{ $template->is_default ? ' · Padrão' : '' }}</small></div><div class="actions">@if(auth()->user()->allows('message_templates',true))<a href="{{ url('/message-templates?edit='.$template->id) }}">Editar</a><form method="post" action="{{ url('/message-templates/'.$template->id.'/delete') }}" data-confirm="Excluir este modelo?">@csrf<button class="secondary">Excluir</button></form>@endif</div></div>@empty<p class="muted">Crie seu primeiro modelo ao lado.</p>@endforelse
</section></div>
@if(auth()->user()->allows('message_templates',true))
<section class="card"><h2>Enviar para um cliente</h2><p class="muted">E-mails entram na fila SMTP. WhatsApp usa a conexão da empresa. Para mensagens livres, o cliente precisa ter autorizado o canal no cadastro.</p>
<form class="stack" method="post" action="{{ url('/message-templates/send') }}" data-confirm="Enviar este modelo ao cliente selecionado?">@csrf<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<div class="form-grid"><label>Modelo<select name="template_id" required><option value="">Selecione</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected($selectedTemplate==$template->id)>{{ $template->name }} · {{ $template->channel==='email' ? 'E-mail' : 'WhatsApp' }}</option>@endforeach</select></label>
<label>Cliente<select name="customer_id" required><option value="">Selecione</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(request('customer_id')==$customer->id)>{{ $customer->name }}</option>@endforeach</select></label>
<label>Número da venda <small>Para comprovantes</small><input name="sale_id" type="number" min="1" value="{{ request('sale_id') }}"></label><label>Número da conta <small>Para cobranças</small><input name="account_id" type="number" min="1" value="{{ request('account_id') }}"></label></div><button @disabled($templates->isEmpty())>Enviar mensagem</button></form></section>
@endif
<section class="card"><h2>Últimos envios de modelos</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Cliente</th><th>Canal</th><th>Resultado</th></tr></thead><tbody>@forelse($deliveries as $delivery)<tr><td>{{ \Carbon\Carbon::parse($delivery->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</td><td>#{{ $delivery->customer_id }}</td><td>{{ $delivery->channel==='email' ? 'E-mail' : 'WhatsApp' }}</td><td>{{ ['pending'=>'Em processamento','queued'=>'Na fila SMTP — consulte os envios em Configurações','sent'=>'Enviado','unknown'=>'Sem confirmação — confira antes de reenviar'][$delivery->status] ?? $delivery->status }}</td></tr>@empty<tr><td colspan="4">Nenhum envio manual realizado.</td></tr>@endforelse</tbody></table></div></section>
@endsection
