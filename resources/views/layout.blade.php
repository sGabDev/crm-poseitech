<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title','Visão geral') · PoseiTech CRM</title>
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">
<script src="{{ asset('assets/app.js') }}?v={{ filemtime(public_path('assets/app.js')) }}" defer>
</script>
</head>
<body>
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
<nav>
<span class="nav-label">SEU NEGÓCIO</span>
@if(isset($company))
@foreach([['/dashboard','Visão geral','sales','◫'],['/opportunities','Oportunidades','customers','↗'],['/sales','Vendas','sales','▤'],['/records/customers','Clientes','customers','◎'],['/records/products','Produtos e serviços','products','▦'],['/cash-flow','Fluxo de caixa','cash','$'],['/cash','Caixa','cash','▣'],['/credit','Fiados','credit','◷'],['/stock','Estoque','stock','▥'],['/orders','Pedidos','orders','☷'],['/campaigns','Campanhas','campaigns','◇'],['/records/coupons','Cupons','loyalty','%'],['/records/suppliers','Fornecedores','finance','□'],['/records/goals','Metas','sales','⚑'],['/reports','Relatórios e previsão','finance','↗']] as [$path,$label,$module,$symbol])
@if($company->enabled($module) && auth()->user()->allows($module))<a class="{{ request()->is(ltrim($path,'/').'*') ? 'active' : '' }}" href="{{ url($path) }}">
<span class="nav-symbol">{{ $symbol }}</span>{{ $label }}</a>@endif
@endforeach
@can('manage-company')<span class="nav-label">GERENCIAR</span>
<a href="{{ url('/alerts') }}">Notificações</a>
@endcan
<a href="{{ url('/settings') }}">Configurações</a>
@endif
@can('platform')<a href="{{ url('/admin') }}">Super Admin PoseiTech</a>@endcan
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
<span class="breadcrumb">Workspace <span>/</span> @yield('title','Visão geral')</span>
<form class="global-search" action="{{ url('/search') }}">
<span>⌕</span>
<input name="q" placeholder="Buscar clientes, vendas, produtos..." aria-label="Busca global" value="{{ request('q') }}">
<kbd>Buscar</kbd>
</form>
<span class="today">{{ now()->format('d/m/Y') }}</span>
</header>
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
</body>
</html>
