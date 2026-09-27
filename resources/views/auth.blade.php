@extends('layout',['public'=>true])
@section('title','Bem-vindo')
@section('content')
<div class="auth-grid">
<section class="auth-intro">
<div class="eyebrow">GESTÃO SIMPLES. DECISÕES MELHORES.</div>
<h1>Seu negócio,<br>em uma visão<br>
<em>mais clara.</em>
</h1>
<p>Conheça seus clientes, acompanhe suas vendas e mantenha o financeiro sob controle.</p>
<div class="auth-points">
<span>✓ Para diferentes tipos de negócio</span>
<span>✓ Módulos que acompanham sua operação</span>
<span>✓ Tudo conectado, do caixa ao cliente</span>
</div>
</section>
<section class="card auth-card">
<span class="eyebrow">POSEITECH CRM</span>
<h2>{{ ['login'=>'Que bom ter você aqui','register'=>'Comece a organizar seu negócio','forgot'=>'Recuperar acesso','reset'=>'Defina uma nova senha'][$mode] }}</h2>
<p class="muted">{{ $mode==='register' ? 'Crie sua empresa e experimente por 14 dias.' : 'Acesse seu espaço de trabalho.' }}</p>
<form method="post" action="{{ url(['login'=>'/login','register'=>'/register','forgot'=>'/forgot-password','reset'=>'/reset-password'][$mode]) }}" class="stack">@csrf
@if($mode==='register')<label>Nome da empresa<input name="company" value="{{ old('company') }}" required maxlength="160">
</label>
<label>Seu nome<input name="name" value="{{ old('name') }}" required maxlength="160">
</label>@endif
<label>E-mail<input type="email" name="email" autocomplete="username" value="{{ old('email',request('email')) }}" required>
</label>
@if($mode!=='forgot')<label>Senha<input type="password" name="password" autocomplete="{{ $mode==='login' ? 'current-password' : 'new-password' }}" required @if($mode!=='login') minlength="10" @endif>
</label>@endif
@if(in_array($mode,['register','reset']))<label>Confirme a senha<input type="password" name="password_confirmation" autocomplete="new-password" minlength="10" required>
</label>
<small class="muted">Use pelo menos 10 caracteres, com letras e números.</small>@endif
@if($mode==='reset')<input type="hidden" name="token" value="{{ $token }}">@endif
@if($mode==='login')<div class="split">
<label class="check">
<input type="checkbox" name="remember" value="1">Lembrar de mim</label>
<a href="{{ url('/forgot-password') }}">Esqueci minha senha</a>
</div>@endif
<button>{{ ['login'=>'Entrar no meu painel →','register'=>'Criar minha empresa →','forgot'=>'Enviar instruções','reset'=>'Salvar nova senha'][$mode] }}</button>
</form>
<p class="auth-switch">@if($mode==='login')Ainda não tem conta? <a href="{{ url('/register') }}">Começar agora</a>@else<a href="{{ url('/login') }}">Voltar para o login</a>@endif</p>
</section>
</div>
@endsection
