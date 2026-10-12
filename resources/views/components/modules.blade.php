<section class="card">
<h2>Módulos da empresa</h2>
<p class="muted">Somente o suporte pode alterar os módulos. Consulte abaixo os módulos ativos.</p>
<p class="muted">O módulo Caixa é obrigatório quando Vendas está ativo.</p>
@if($company->enabled('whatsapp') || $company->enabled('message_templates'))<div class="actions">
@if($company->enabled('whatsapp'))<a class="button secondary" href="{{ url('/whatsapp') }}">Conexão do WhatsApp</a>@endif
@if($company->enabled('message_templates') && auth()->user()->allows('message_templates'))<a class="button secondary" href="{{ url('/message-templates') }}">Gerenciar modelos de mensagem</a>@endif
</div>@endif
@can('platform')
<form method="post" action="{{ url('/settings') }}" class="stack">@csrf<input type="hidden" name="section" value="modules">
@endcan
<div class="module-grid">@foreach(config('poseitech.modules') as $key=>$label)<label class="check">
<input type="checkbox" name="modules[]" value="{{ $key }}" @checked($company->enabled($key)) @disabled(!auth()->user()->can('platform'))>{{ $label }}</label>@endforeach</div>
@can('platform')
<button>Salvar módulos</button>
</form>
@endcan
</section>
