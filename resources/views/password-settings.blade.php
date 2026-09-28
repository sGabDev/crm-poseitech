@extends('layout')
@section('title','Configurações')
@section('content')
@include('components.modules')
<section class="card"><h1>Configurações</h1><h2>Minha senha</h2>@include('components.password-form')</section>
@endsection
