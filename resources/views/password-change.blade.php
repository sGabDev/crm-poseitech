@extends('layout')
@section('title','Alterar senha')
@section('content')
<section class="card receipt"><h1>Alterar senha</h1>
@if(auth()->user()->must_change_password)<p>Antes de continuar, escolha uma nova senha pessoal.</p>@endif
@include('components.password-form')
<form method="post" action="{{ url('/logout') }}">@csrf<button class="secondary">Sair</button></form>
</section>
@endsection
