@extends('layout')
@section('title','Vendas')
@section('content')<div class="page-heading">
<div>
<h1>Vendas</h1>
<p>Acompanhe cada venda, do registro ao recebimento.</p>
</div>
<a class="button" href="{{ url('/sales/new') }}">+ Nova venda</a>
</div>@include('components.period')<section class="card">@include('components.sales-table'){{ $sales->links() }}</section>@endsection
