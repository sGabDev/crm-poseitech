@php
$code=(int)($exception?->getStatusCode() ?? 500);
$messages=[
403=>['Acesso não permitido','Você não tem permissão para acessar esta página. Entre com a conta correta ou procure o suporte.'],
404=>['Página não encontrada','O endereço pode ter mudado ou não estar mais disponível. Confira o link e tente novamente.'],
405=>['Ação não disponível','Este endereço não aceita a ação solicitada. Volte à página inicial.'],
419=>['Sua sessão expirou','Abra a página novamente antes de enviar o formulário. Seus dados já salvos continuam preservados.'],
422=>['Confira os dados informados','Não foi possível concluir a solicitação. Revise os campos e tente novamente.'],
429=>['Muitas tentativas em pouco tempo','Aguarde um momento antes de tentar novamente.'],
500=>['Não foi possível carregar esta página','Ocorreu uma falha inesperada. Tente novamente em instantes ou entre em contato com o suporte.'],
502=>['Serviço temporariamente indisponível','Não foi possível comunicar com um serviço necessário. Tente novamente em instantes.'],
503=>['Voltamos em instantes','Estamos realizando uma atualização ou o serviço está temporariamente indisponível.'],
504=>['A resposta demorou mais que o esperado','Tente novamente em instantes.']
];
[$heading,$description]=$messages[$code] ?? ['Não foi possível concluir a solicitação','Tente novamente ou procure o suporte.'];
@endphp
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>{{ $code }} · {{ $heading }} · PoseiTech</title><meta name="description" content="{{ $description }}"><link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}"><link rel="stylesheet" href="{{ asset('assets/error.css') }}"></head><body><main class="error-card"><a class="error-brand" href="{{ url('/') }}">PoseiTech</a><span class="error-code" aria-hidden="true">{{ $code }}</span><h1>{{ $heading }}</h1><p>{{ $description }}</p><div class="error-actions"><a class="button" href="{{ url('/') }}">Ir para o início</a><a class="button secondary" href="{{ url('/support') }}">Procurar suporte</a></div></main></body></html>
