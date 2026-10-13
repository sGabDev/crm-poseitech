@foreach($messages as $message)
<article class="support-message {{ $message->author_role }} {{ $message->author_role===($platform ? 'support' : 'company') ? 'self' : '' }}" data-message-id="{{ $message->id }}">
<header><strong>{{ $message->author_name }} <span>{{ ['support'=>'Suporte técnico','company'=>'Empresa','system'=>'Atualização'][$message->author_role] }}</span></strong><time>{{ \Carbon\Carbon::parse($message->created_at)->timezone($company->timezone ?? 'America/Sao_Paulo')->format('d/m/Y H:i') }}</time></header>
@if($message->body)<p>{{ $message->body }}</p>@endif
@foreach($message->attachments as $file)
<a class="support-file" href="{{ url($base.'/'.$id.'/files/'.$file->id) }}">
@if(in_array($file->mime,['image/jpeg','image/png','image/webp']))<img loading="lazy" src="{{ url($base.'/'.$id.'/files/'.$file->id.'?preview=1') }}" alt="{{ $file->name }}">@endif
<span>{{ $file->name }} <small>{{ number_format($file->size/1024,0,',','.') }} KB · Baixar</small></span></a>
@endforeach
</article>
@endforeach
