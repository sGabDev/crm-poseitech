@extends('layout')
@section('title','Atualização pendente')
@section('content')
<section class="card receipt"><h1>Atualização do banco pendente</h1>
<p>Os arquivos foram atualizados, mas o banco ainda precisa ser atualizado. Solicite ao responsável pela hospedagem a conclusão da atualização.</p>
@can('manage-company')
<p>No terminal da Hostinger, na pasta que contém o arquivo artisan, execute:</p>
<pre>/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear</pre>
@endcan
</section>
@endsection
