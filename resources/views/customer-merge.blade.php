@extends('layout')
@section('title','Unir clientes')
@section('content')
<div class="page-heading"><div><a href="{{ url('/customers/'.$keep->id) }}">← Perfil do cliente</a><h1>Unir clientes duplicados</h1><p>O cadastro que permanece é <strong>{{ $keep->name }}</strong> · #{{ $keep->id }}.</p></div></div>
@if(!isset($duplicate))
<section class="card"><h2>Qual cadastro é a duplicata?</h2><p class="muted">Pesquise e compare antes de confirmar. Nenhuma alteração será feita nesta etapa.</p>
<form method="get" class="filters"><label>Nome, e-mail, telefone ou documento<input name="q" maxlength="100" value="{{ request('q') }}" placeholder="Buscar outro cadastro"></label><button class="secondary">Pesquisar</button></form>
<div class="table-wrap"><table><thead><tr><th>Cliente</th><th>E-mail</th><th>Telefone / documento</th><th></th></tr></thead><tbody>@forelse($candidates as $candidate)<tr><td>{{ $candidate->name }} <small>#{{ $candidate->id }}</small></td><td>{{ $candidate->email ?: '—' }}</td><td>{{ $candidate->phone ?: $candidate->document ?: '—' }}</td><td><a class="button secondary" href="{{ url('/customers/'.$keep->id.'/merge?duplicate_id='.$candidate->id) }}">Comparar cadastros</a></td></tr>@empty<tr><td colspan="4">Nenhum outro cliente encontrado.</td></tr>@endforelse</tbody></table></div>{{ $candidates->links() }}</section>
@else
<section class="card"><div class="page-heading"><div><h2>Confira os dois cadastros</h2><p class="muted">Tags e observações serão combinadas. Dados diferentes também ficam registrados nas observações e na auditoria.</p></div><a href="{{ url('/customers/'.$duplicate->id.'/merge?duplicate_id='.$keep->id) }}">Inverter cadastro que permanece</a></div>
<form method="post" action="{{ url('/customers/'.$keep->id.'/merge') }}">@csrf
<input type="hidden" name="duplicate_id" value="{{ $duplicate->id }}"><input type="hidden" name="version" value="{{ $version }}">
<div class="table-wrap"><table><thead><tr><th>Informação</th><th>Permanece · #{{ $keep->id }}</th><th>Duplicata · #{{ $duplicate->id }}</th><th>Usar no cadastro final</th></tr></thead><tbody>
@foreach(\App\Services\CustomerMerge::FIELDS as $field=>$label)<tr><td>{{ $label }}</td><td>{{ $keep->$field ?: '—' }}</td><td>{{ $duplicate->$field ?: '—' }}</td><td><select name="choices[{{ $field }}]" aria-label="{{ $label }} do cadastro final"><option value="keep" @selected(old('choices.'.$field,filled($keep->$field)?'keep':'duplicate')==='keep')>{{ $keep->$field ?: 'Não informado' }} · #{{ $keep->id }}</option><option value="duplicate" @selected(old('choices.'.$field,filled($keep->$field)?'keep':'duplicate')==='duplicate')>{{ $duplicate->$field ?: 'Não informado' }} · #{{ $duplicate->id }}</option></select></td></tr>@endforeach
@foreach(['tags'=>'Tags','notes'=>'Observações'] as $field=>$label)<tr><td>{{ $label }}</td><td style="white-space:pre-wrap">{{ $keep->$field ?: '—' }}</td><td style="white-space:pre-wrap">{{ $duplicate->$field ?: '—' }}</td><td>Combinar os dois</td></tr>@endforeach
@foreach(['sales'=>'Vendas','payments'=>'Pagamentos','whatsapp_conversations'=>'Conversas','email_logs'=>'E-mails','wallet'=>'Saldo da conta','debt'=>'Dívidas pendentes'] as $field=>$label)<tr><td>{{ $label }}</td><td>{{ in_array($field,['wallet','debt']) ? \App\Services\Tenant::money($summaries[$keep->id][$field]) : $summaries[$keep->id][$field] }}</td><td>{{ in_array($field,['wallet','debt']) ? \App\Services\Tenant::money($summaries[$duplicate->id][$field]) : $summaries[$duplicate->id][$field] }}</td><td>Reunir no cadastro #{{ $keep->id }}</td></tr>@endforeach
</tbody></table></div>
<div class="separated"><p>Os históricos são preservados e os valores financeiros não são alterados. Conversas de números diferentes continuam separadas, vinculadas ao mesmo cliente.</p><p class="muted">Os links antigos do portal serão revogados. Autorizações de marketing só permanecem quando os dois cadastros autorizam o mesmo contato. Campanhas pendentes duplicadas e benefícios extras do mesmo cupom serão desativados.</p>
<label class="check"><input type="checkbox" name="confirmation" value="1" required>Conferi os dados e confirmo que são a mesma pessoa. O cadastro #{{ $duplicate->id }} será removido após transferir o histórico. Esta operação não tem desfazer automático.</label>
<div class="actions"><button>Confirmar união dos clientes</button><a class="button secondary" href="{{ url('/customers/'.$keep->id.'/merge') }}">Escolher outro cliente</a></div></div>
</form></section>
@endif
@endsection
