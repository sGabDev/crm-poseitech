@extends('layout')
@section('title','Modelos de mensagem')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/message-templates.css') }}?v={{ filemtime(public_path('assets/message-templates.css')) }}">
<script src="{{ asset('assets/whatsapp-format.js') }}?v={{ filemtime(public_path('assets/whatsapp-format.js')) }}" defer></script>
<script src="{{ asset('assets/receipt-designer.js') }}?v={{ filemtime(public_path('assets/receipt-designer.js')) }}" defer></script>
<script src="{{ asset('assets/message-templates.js') }}?v={{ filemtime(public_path('assets/message-templates.js')) }}" defer></script>
@php($canEdit = auth()->user()->allows('message_templates',true))
<div class="template-page">
<div class="page-heading"><div><h1>Modelos de mensagem</h1><p>Escolha um modelo, personalize e veja como vai chegar ao cliente.</p></div>@if($canEdit)<a class="button" href="{{ url('/message-templates?new=1') }}">+ Novo modelo</a>@endif</div>
<div class="template-workspace">
<aside class="template-library">
<div class="template-library-heading"><h2>Seus modelos</h2><span>{{ $templates->count() }}</span></div>
<input type="search" placeholder="Buscar modelo" aria-label="Buscar modelo" data-template-search>
<nav aria-label="Modelos de mensagem" class="template-list">
@foreach($templates as $template)
<a class="template-choice {{ ($editing->id ?? null)===$template->id ? 'selected' : '' }}" href="{{ url('/message-templates?edit='.$template->id) }}" data-template-choice @if(($editing->id ?? null)===$template->id)aria-current="page"@endif>
<span class="template-channel {{ $template->channel }}" aria-hidden="true">{{ $template->channel==='email' ? '✉' : '◉' }}</span><span><strong>{{ $template->name }}</strong><small>{{ $template->channel==='email' ? 'E-mail' : 'WhatsApp' }} · {{ \App\Services\MessageTemplates::PURPOSES[$template->purpose] }}</small>@if($template->is_default)<em>Padrão</em>@endif</span></a>
@endforeach
<p class="muted" data-library-empty @if($templates->isNotEmpty())hidden @endif>Nenhum modelo encontrado.</p>
</nav><p class="template-library-note">Os modelos padrão são usados nos envios do CRM.</p>
</aside>
<section class="template-editor-panel">
<div class="template-panel-heading"><div><span class="template-eyebrow">PERSONALIZAR</span><h2>{{ $editing ? 'Editar modelo' : 'Criar modelo' }}</h2></div><span class="template-save-state" data-save-state>Pronto para editar</span></div>
@if($canEdit)
<form class="template-form" method="post" action="{{ url('/message-templates'.($editing ? '/'.$editing->id : '')) }}" data-template-editor>@csrf
<div class="template-fields">
<label>Nome do modelo<input name="name" maxlength="120" value="{{ old('name',$editing->name ?? '') }}" placeholder="Ex.: Comprovante de compra" required></label>
<div class="form-grid"><label>Canal<select name="channel"><option value="email" @selected(old('channel',$editing->channel ?? '')==='email')>E-mail</option><option value="whatsapp" @selected(old('channel',$editing->channel ?? '')==='whatsapp')>WhatsApp</option></select></label><label>Usar para<select name="purpose">@foreach(\App\Services\MessageTemplates::PURPOSES as $key=>$label)<option value="{{ $key }}" @selected(old('purpose',$editing->purpose ?? 'general')===$key)>{{ $label }}</option>@endforeach</select></label></div>
<label data-subject>Assunto do e-mail<input name="subject" maxlength="200" value="{{ old('subject',$editing->subject ?? '') }}" placeholder="Seu comprovante · {empresa}"></label>
<section data-receipt-html hidden>
<div class="template-section-title"><h3>Aparência do comprovante</h3><span>Editor fácil</span></div>
<p class="template-help">Altere os campos abaixo. Itens, valores e pagamentos continuam automáticos.</p>
<div class="template-colors"><label>Topo<input type="color" data-design-color="header" aria-label="Cor do topo"></label><label>Botão<input type="color" data-design-color="button" aria-label="Cor do botão"></label><label>Fundo<input type="color" data-design-color="background" aria-label="Cor do fundo"></label></div>
<label>Título do topo<input data-design-text="heading" maxlength="180" placeholder="COMPROVANTE DIGITAL"></label>
<label>Saudação<input data-design-text="greeting" maxlength="200" placeholder="Olá, {nome}!"></label>
<label>Texto de apresentação<textarea data-design-text="intro" rows="3" maxlength="2000"></textarea></label>
<label>Texto do botão<input data-design-text="button" maxlength="160" placeholder="Abrir comprovante completo →"></label>
<p class="template-help" data-design-warning hidden>Seu HTML tem uma estrutura personalizada. Os campos disponíveis podem ser editados aqui; o restante está em “Opções avançadas”.</p>
</section>
<div class="template-section-title"><h3 data-body-heading>Mensagem</h3><span data-body-counter></span></div>
<div class="template-message-box"><div class="template-format-tools" data-format-tools hidden><button type="button" data-wrap="*" aria-label="Negrito"><strong>B</strong></button><button type="button" data-wrap="_" aria-label="Itálico"><i>I</i></button><button type="button" data-wrap="~" aria-label="Tachado"><s>S</s></button><button type="button" data-wrap="`" aria-label="Código">&lt;/&gt;</button></div><textarea name="body" rows="5" maxlength="7000" required placeholder="Olá, {nome}! Aqui é da {empresa}.">{{ old('body',$editing->body ?? '') }}</textarea></div>
<p class="template-help" data-body-help>Escreva como você fala com seus clientes.</p>
<div class="template-variable-heading"><span>Adicionar informação do cliente</span><small>Inserir no campo selecionado</small></div><div class="template-variable-list" data-variables></div>
<label class="template-default check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default',$editing->is_default ?? false))><span>Usar como modelo padrão<small>Aplicar nos próximos envios desta finalidade e canal.</small></span></label>
<details class="template-advanced" data-receipt-advanced hidden><summary>Opções avançadas · HTML do comprovante</summary><p class="template-help">Edite o código completo usando estilos inline. A prévia usa dados ilustrativos.</p><label>Código HTML<textarea name="html_body" rows="16" maxlength="60000" spellcheck="false">{{ old('html_body',$editing->html_body ?? \App\Services\ReceiptHtml::defaults()) }}</textarea></label><button type="button" class="secondary" data-restore-receipt>Restaurar padrão do CRM</button><template data-default-receipt>{{ \App\Services\ReceiptHtml::defaults() }}</template></details>
</div>
<div class="template-editor-footer"><span>Confira a prévia antes de salvar.</span><button>{{ $editing ? 'Salvar alterações' : 'Criar modelo' }}</button></div>
</form>
@else<p class="template-readonly">Você tem acesso de leitura. Peça permissão ao administrador para editar.</p>@endif
</section>
<aside class="template-preview-panel"><div class="template-panel-heading"><div><span class="template-eyebrow">COMO O CLIENTE RECEBE</span><h2>Prévia da mensagem</h2></div><span class="template-live"><i></i> Ao vivo</span></div><div class="template-preview-content"><div class="template-preview-envelope"><small data-preview-channel>E-mail</small><strong data-preview-subject>{{ $editing->subject ?? '' }}</strong></div><iframe data-html-preview title="Prévia do comprovante por e-mail" sandbox="" referrerpolicy="no-referrer" hidden></iframe><div class="template-text-preview"><p data-template-preview>{{ $editing->body ?? 'Sua mensagem aparecerá aqui.' }}</p><small data-preview-time></small></div></div><p class="template-preview-note">Exemplo com dados ilustrativos. No envio, usamos os dados reais do cliente.</p></aside>
</div>
@if($canEdit)
<details class="template-disclosure" @if(request('customer_id') || request('account_id') || request('sale_id'))open @endif><summary><span>Enviar para um cliente<small>Use um modelo salvo em um envio manual.</small></span><span aria-hidden="true">↗</span></summary>
<form class="template-send-form" method="post" action="{{ url('/message-templates/send') }}" data-template-send data-confirm="Enviar este modelo ao cliente selecionado?">@csrf<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><div class="form-grid">
<label>Modelo<select name="template_id" required><option value="">Selecione</option>@foreach($templates as $template)<option value="{{ $template->id }}" data-purpose="{{ $template->purpose }}" @selected((request('purpose') ? $selectedTemplate : ($editing->id ?? $selectedTemplate))==$template->id)>{{ $template->name }} · {{ $template->channel==='email' ? 'E-mail' : 'WhatsApp' }}</option>@endforeach</select></label>
<label>Cliente<select name="customer_id" required><option value="">Selecione</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(request('customer_id')==$customer->id)>{{ $customer->name }}</option>@endforeach</select></label>
<label data-send-sale>Número da venda<input name="sale_id" type="number" min="1" value="{{ request('sale_id') }}"></label><label data-send-account>Número da conta<input name="account_id" type="number" min="1" value="{{ request('account_id') }}"></label></div><div class="template-send-bottom"><p class="template-help">E-mails usam a fila de envio. WhatsApp usa a conexão da empresa.</p><button @disabled($templates->isEmpty())>Enviar mensagem</button></div></form></details>
@endif
<details class="template-disclosure"><summary><span>Histórico de envios<small>Últimas mensagens enviadas com modelos.</small></span><span aria-hidden="true">↗</span></summary><div class="table-wrap"><table><thead><tr><th>Data</th><th>Cliente</th><th>Canal</th><th>Resultado</th></tr></thead><tbody>@forelse($deliveries as $delivery)<tr><td>{{ \Carbon\Carbon::parse($delivery->created_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</td><td>#{{ $delivery->customer_id }}</td><td>{{ $delivery->channel==='email' ? 'E-mail' : 'WhatsApp' }}</td><td>{{ ['pending'=>'Em processamento','queued'=>'Na fila de envio','sent'=>'Enviado','sending'=>'Enviando','failed'=>'Falhou','cancelled'=>'Cancelado','unknown'=>'Sem confirmação — confira antes de reenviar'][$delivery->status] ?? $delivery->status }}</td></tr>@empty<tr><td colspan="4">Nenhum envio manual realizado.</td></tr>@endforelse</tbody></table></div></details>
@if($canEdit && $editing)<div class="template-delete"><span>Não precisa mais deste modelo?</span><form method="post" action="{{ url('/message-templates/'.$editing->id.'/delete') }}" data-confirm="Excluir este modelo?">@csrf<button class="secondary">Excluir modelo</button></form></div>@endif
</div>
@endsection
