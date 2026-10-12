@extends('layout')
@section('title','Anexo indisponível')
@section('content')
<section class="card"><h1>Não foi possível baixar este anexo.</h1><p>O arquivo pode ter expirado no WhatsApp, excedido 8 MB ou o bot está desconectado. Confira também no celular.</p><a class="button" href="{{ url('/whatsapp/atendimento') }}">Voltar ao atendimento</a></section>
@endsection
