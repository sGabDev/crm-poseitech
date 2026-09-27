<form class="filters" method="get">
<label>Período<select name="period">@foreach(['today'=>'Hoje','yesterday'=>'Ontem','7'=>'Últimos 7 dias','30'=>'Últimos 30 dias','month'=>'Mês atual','previous'=>'Mês anterior','year'=>'Ano atual','custom'=>'Personalizado'] as $v=>$l)<option value="{{ $v }}" @selected(request('period','month')===(string)$v)>{{ $l }}</option>@endforeach</select>
</label>
<label>De<input type="date" name="from" value="{{ request('from') }}">
</label>
<label>Até<input type="date" name="to" value="{{ request('to') }}">
</label>@if($paymentFilter ?? false)<label>Pagamento<select name="method">
<option value="">Todos</option>@foreach(config('poseitech.methods')+['fiado'=>'Fiado'] as $v=>$l)<option value="{{ $v }}" @selected(request('method')===$v)>{{ $l }}</option>@endforeach</select>
</label>@endif<button class="secondary">Aplicar filtros</button>
</form>
