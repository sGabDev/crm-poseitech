@extends('layout')
@section('title','Chamado #'.$ticket->id)
@section('body-class','support-page')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/settings-support.css') }}?v={{ filemtime(public_path('assets/settings-support.css')) }}">
<section class="ticket-workspace" aria-label="Atendimento do chamado">
    <header class="ticket-toolbar">
        <a class="ticket-back" href="{{ url($base) }}" aria-label="Voltar aos chamados">←</a>
        <div class="ticket-identity"><small>Chamado #{{ $ticket->id }} · {{ $ticket->company_name }}</small><h1>{{ $ticket->subject }}</h1></div>
        <span class="badge ticket-status">{{ \App\Http\Controllers\SupportTicketController::STATUSES[$ticket->status] }}</span>
        <details class="ticket-controls">
            <summary>Detalhes e status</summary>
            <div class="ticket-controls-panel">
                <h2>Detalhes do chamado</h2>
                <dl class="list-detail"><dt>Categoria</dt><dd>{{ \App\Http\Controllers\SupportTicketController::CATEGORIES[$ticket->category] }}</dd><dt>Prioridade</dt><dd>{{ \App\Http\Controllers\SupportTicketController::PRIORITIES[$ticket->priority] }}</dd><dt>Aberto em</dt><dd>{{ \Carbon\Carbon::parse($ticket->created_at)->timezone($company->timezone ?? 'America/Sao_Paulo')->format('d/m/Y H:i') }}</dd></dl>
                <form method="post" action="{{ url($base.'/'.$ticket->id.'/status') }}" class="stack">@csrf
                    <label>Status<select name="status">@foreach(\App\Http\Controllers\SupportTicketController::STATUSES as $key=>$label)@continue(!$platform && !in_array($key,['waiting_support','resolved','closed']))<option value="{{ $key }}" @selected($ticket->status===$key)>{{ $key==='waiting_support' && in_array($ticket->status,['resolved','closed']) ? 'Reabrir chamado' : $label }}</option>@endforeach</select></label>
                    @if($platform)<label class="check"><input type="checkbox" name="assign" value="1">Assumir este atendimento</label>@endif
                    <button>Atualizar chamado</button>
                </form>
            </div>
        </details>
    </header>
    <div class="support-chat" data-support-chat data-history="{{ url($base.'/'.$ticket->id.'/history') }}" tabindex="0" aria-label="Histórico da conversa">
        <button type="button" class="secondary support-older" data-older>Ver mensagens anteriores</button>
        <div class="support-thread">@include('components.support-messages',['id'=>$ticket->id])</div>
    </div>
    <button type="button" class="secondary ticket-new-messages" data-chat-notice hidden>Novas mensagens ↓</button>
    @if($ticket->status!=='closed')
    <form method="post" enctype="multipart/form-data" action="{{ url($base.'/'.$ticket->id.'/reply') }}" class="ticket-composer">@csrf
        <input type="hidden" name="request_key" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
        <label class="ticket-attach" title="Até 5 arquivos de 5 MB cada"><span>Anexar</span><input data-file-input class="visually-hidden" type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.txt,.doc,.docx,.xls,.xlsx,.zip"><small class="ticket-file-status" hidden aria-live="polite"></small></label>
        <label class="ticket-draft"><span class="visually-hidden">Sua resposta</span><textarea data-support-draft name="body" maxlength="8000" rows="1" placeholder="Escreva uma resposta...">{{ old('body') }}</textarea></label>
        <button>Enviar resposta</button>
    </form>
    @else
    <p class="ticket-closed">Chamado encerrado. Abra “Detalhes e status” para reabrir a conversa.</p>
    @endif
</section>
<script src="{{ asset('assets/support-chat.js') }}?v={{ filemtime(public_path('assets/support-chat.js')) }}" defer></script>
@endsection
