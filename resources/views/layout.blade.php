<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title','Visão geral') · PoseiTech CRM</title>
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/layout-fixes.css') }}?v={{ filemtime(public_path('assets/layout-fixes.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/refresh.css') }}?v={{ filemtime(public_path('assets/refresh.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/minimal.css') }}?v={{ filemtime(public_path('assets/minimal.css')) }}">
<script src="{{ asset('assets/app.js') }}?v={{ filemtime(public_path('assets/app.js')) }}" defer>
</script>
</head>
<body class="{{ View::hasSection('immersive') ? 'inbox-page' : '' }} @yield('body-class')">
@hasSection('immersive')
<main class="inbox-fullscreen">@yield('content')</main>
@else
@php($public = $public ?? false)
@if(!$public && auth()->check())
<aside class="sidebar" id="sidebar">
<a class="brand" href="{{ url('/dashboard') }}">
<span class="brand-mark">P</span>
<span>PoseiTech<small>CRM & GESTÃO</small>
</span>
</a>
<div class="workspace">
@if($company->logo ?? null)<img class="avatar" src="{{ url('/logo/'.$company->id) }}" alt="Logo da empresa">@else<span class="avatar">{{ mb_substr($company->name ?? 'PoseiTech',0,1) }}</span>@endif
<div>
<strong>{{ $company->name ?? 'PoseiTech' }}</strong>
<small>{{ $company->plan->name ?? 'Administração da plataforma' }}</small>
</div>
</div>
<nav aria-label="Navegação principal">
@if(isset($company) && $company->enabled('whatsapp') && $company->enabled('customers') && auth()->user()->allows('customers'))
<a class="{{ request()->is('whatsapp/atendimento*') ? 'active' : '' }}" href="{{ url('/whatsapp/atendimento') }}"><span class="nav-symbol">◉</span>WhatsApp · Atendimento</a>
@endif
<span class="nav-label">Vendas e clientes</span>
@if(isset($company))
@foreach([
    'Vendas e clientes' => [['/dashboard','Visão geral','sales','◫'],['/sales','Vendas','sales','▤'],['/records/customers','Clientes','customers','◎'],['/opportunities','Oportunidades','customers','↗']],
    'Operação' => [['/records/products','Produtos e serviços','products','▦'],['/orders','Pedidos','orders','☷'],['/stock','Estoque','stock','▥'],['/records/suppliers','Fornecedores','finance','□']],
    'Financeiro' => [['/cash','Caixa','cash','▣'],['/cash-flow','Fluxo de caixa','cash','$'],['/credit','Fiados','credit','◷'],['/reports','Relatórios e previsão','finance','↗']],
    'Crescimento' => [['/campaigns','Campanhas','campaigns','◇'],['/records/coupons','Cupons','loyalty','%'],['/records/goals','Metas','sales','⚑']],
] as $group => $entries)
@php($visibleEntries = array_filter($entries, fn ($entry) => $company->enabled($entry[2]) && auth()->user()->allows($entry[2])))
@if(count($visibleEntries))
@if($group !== 'Vendas e clientes')<span class="nav-label">{{ $group }}</span>@endif
@foreach($visibleEntries as [$path,$label,$module,$symbol])
<a class="{{ request()->is(ltrim($path,'/'),ltrim($path,'/').'/*') ? 'active' : '' }}" href="{{ url($path) }}" @if(request()->is(ltrim($path,'/'),ltrim($path,'/').'/*'))aria-current="page"@endif>
<span class="nav-symbol" aria-hidden="true">{{ $symbol }}</span>{{ $label }}</a>
@endforeach
@endif
@endforeach
@can('manage-company')<span class="nav-label">GERENCIAR</span>
@if($company->enabled('whatsapp'))<a class="{{ request()->is('whatsapp') ? 'active' : '' }}" href="{{ url('/whatsapp') }}">Conexão do WhatsApp</a>@endif
<a href="{{ url('/alerts') }}">Notificações</a>
@endcan
<a class="{{ request()->is('support*') ? 'active' : '' }}" href="{{ url('/support') }}">Suporte técnico</a>
<a href="{{ url('/settings') }}">Configurações</a>
@if($company->enabled('message_templates') && auth()->user()->allows('message_templates'))<a class="{{ request()->is('message-templates*') ? 'active' : '' }}" href="{{ url('/message-templates') }}">Modelos de mensagem</a>@endif
@endif
@can('platform')<a class="{{ request()->is('admin/tickets*') ? 'active' : '' }}" href="{{ url('/admin/tickets') }}">Central de chamados</a><a href="{{ url('/admin') }}">Super Admin PoseiTech</a>@endcan
</nav>
<div class="sidebar-bottom">
<span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span>
<div>
<strong>{{ auth()->user()->name }}</strong>
<small>{{ ['admin'=>'Administrador','staff'=>'Equipe','super'=>'Super Admin'][auth()->user()->role] }}</small>
</div>
<form method="post" action="{{ url('/logout') }}">@csrf<button class="icon-button" title="Sair">↪</button>
</form>
</div>
</aside>
<div class="app">
<header class="topbar">
<button class="icon-button menu-toggle" aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false">☰</button>
<span class="breadcrumb">@yield('title','Visão geral')</span>
<form class="global-search" action="{{ url('/search') }}">
<span>⌕</span>
<input name="q" placeholder="Buscar clientes, vendas, produtos..." aria-label="Busca global" value="{{ request('q') }}">
<kbd>Buscar</kbd>
</form>
<span class="today">{{ now()->format('d/m/Y') }}</span>
</header>
<button class="nav-backdrop" type="button" aria-label="Fechar menu" hidden></button>
@if(session('support_company'))<div class="support-banner">Acesso de suporte ativo. Ações registradas em auditoria.<form method="post" action="{{ url('/admin/leave') }}">@csrf<button>Encerrar suporte</button>
</form>
</div>@endif
<main>
@else<div class="public-shell">
<a class="brand" href="{{ url('/') }}">
<span class="brand-mark">P</span>
<span>PoseiTech<small>CRM & GESTÃO</small>
</span>
</a>
<main>@endif
@if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert">
<strong>Revise os dados informados.</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>@endif
@yield('content')
</main>
<footer>PoseiTech CRM <span>Mais clareza para o seu negócio.</span>
</footer>
</div>
@endif
</body>
</html>
