<section class="card">
<h2>Exportações por módulo</h2>
<form class="filters">
<input type="hidden" name="export" value="csv">
<input type="hidden" name="period" value="{{ request('period','month') }}">
<input type="hidden" name="from" value="{{ request('from') }}">
<input type="hidden" name="to" value="{{ request('to') }}">
<label>Relatório<select name="report">@foreach(['sales'=>'Vendas','customers'=>'Clientes','products'=>'Produtos','stock'=>'Movimentações de estoque','cash'=>'Caixa','finance'=>'Financeiro','credit'=>'Fiados','payments'=>'Pagamentos','campaigns'=>'Campanhas','loyalty'=>'Fidelidade'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
</label>
<button class="secondary">Baixar CSV</button>
</form>
<p class="muted">Os lançamentos seguem o período selecionado. Cadastros de clientes e produtos incluem toda a base.</p>
</section>
<section class="card">
<h2>Fluxo de caixa por período</h2>
<form class="filters">@foreach(request()->only('period','from','to') as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach<label>Agrupar<select name="group">
<option value="day" @selected(request('group','day')==='day')>Dia</option>
<option value="week" @selected(request('group')==='week')>Semana</option>
<option value="month" @selected(request('group')==='month')>Mês</option>
</select>
</label>
<button class="secondary">Aplicar</button>
</form>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Período</th>
<th>Entradas</th>
<th>Saídas</th>
<th>Saldo</th>
</tr>
</thead>
<tbody>@foreach($flow as $date=>$values)<tr>
<td>{{ $date }}</td>
<td>{{ \App\Services\Tenant::money($values['in']) }}</td>
<td>{{ \App\Services\Tenant::money($values['out']) }}</td>
<td>{{ \App\Services\Tenant::money($values['in']-$values['out']) }}</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>
<div class="grid">
<section class="card">
<h2>Vendas por horário</h2>@foreach($hourTotals as $hour=>$amount)<div class="metric-row">
<span>{{ $hour }}h</span>
<strong>{{ \App\Services\Tenant::money($amount) }}</strong>
</div>@endforeach</section>
<section class="card">
<h2>Previsão com sazonalidade semanal</h2>
<p class="muted">Estimativa dos últimos 56 dias, ajustada pelo dia da semana e tendência recente limitada a ±50%. Dias sem vendas também entram na média.</p>@foreach(['Média diária'=>'daily','Média semanal'=>'weekly','Amanhã'=>'tomorrow','Próximos 7 dias'=>'seven','Mês atual completo'=>'month','Próximo mês'=>'next_month'] as $label=>$key)<div class="metric-row">
<span>{{ $label }}</span>
<strong>{{ \App\Services\Tenant::money($forecastDetails[$key]) }}</strong>
</div>@endforeach<p class="muted">{{ $forecastDetails['sample'] }} dias com vendas na amostra. Tendência: {{ round($forecastDetails['trend']) }}%. Estimativa, sem garantia de resultado.</p>
</section>
</div>
