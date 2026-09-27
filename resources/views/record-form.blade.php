@extends('layout')
@section('title',$spec['title'])
@section('content')<div class="page-heading">
<div>
<a href="{{ url('/records/'.$resource) }}">← {{ $spec['title'] }}</a>
<h1>{{ $record ? 'Editar cadastro' : 'Novo cadastro' }}</h1>
</div>
</div>
<form method="post" enctype="multipart/form-data" action="{{ url('/records/'.$resource.($record ? '/'.$record->id : '')) }}" class="card form-grid">@csrf
@foreach($spec['fields'] as $key=>$f)
@php($value=old($key,isset($record->$key) ? ($f[1]==='money' ? number_format($record->$key/100,2,'.','') : $record->$key) : ($f[1]==='money' || $f[1]==='number' ? 0 : '')))
<label class="{{ $f[1]==='textarea' ? 'full' : '' }}">{{ str_replace('R$',$company->currency,$f[0]) }}
@if($key==='addons' && !old('addons') && $record) @php($value=collect(json_decode($record->addons ?? '[]',true))->map(fn($a)=>$a['name'].' | '.number_format($a['price']/100,2,'.',''))->implode(PHP_EOL)) @endif
@if($f[1]==='textarea')<textarea name="{{ $key }}" rows="3">{{ $value }}</textarea>
@elseif($f[1]==='checkbox')<span class="check">
<input type="checkbox" name="{{ $key }}" value="1" @checked(old($key,$record->$key ?? ($key==='active')))>
<span>Sim</span>
</span>
@elseif($f[1]==='customer')<select name="{{ $key }}">
<option value="">Qualquer cliente</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected((string)$value===(string)$c->id)>{{ $c->name }}</option>@endforeach</select>
@elseif(str_starts_with($f[1],'select:'))<select name="{{ $key }}">@foreach(explode(',',substr($f[1],7)) as $option)@php([$v,$l]=explode('=',$option))<option value="{{ $v }}" @selected((string)$value===$v)>{{ $l }}</option>@endforeach</select>
@else<input type="{{ $f[1]==='money' ? 'number' : $f[1] }}" name="{{ $key }}" @if($f[1]!=='file')value="{{ $value }}"@endif @if($f[1]==='money')step="0.01" min="0"@endif @if(str_contains($f[2],'required'))required @endif>
@endif</label>@endforeach<div class="full actions">
<button>Salvar cadastro</button>
<a class="button secondary" href="{{ url('/records/'.$resource) }}">Voltar</a>
</div>
</form>@endsection
