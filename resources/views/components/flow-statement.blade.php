<section class="card flow-statement"><h2>Extrato</h2>
<p class="muted">Uma linha por dia e modo. Clique no modo para abrir as entradas e saídas por origem. Diferença = entradas − saídas.</p>
<div class="table-wrap"><table class="statement-table"><thead><tr><th>Data</th><th>Modo</th><th>Descrição</th><th>Entrada</th><th>Saída</th><th>Diferença</th></tr></thead><tbody>
@forelse($groups as $index=>$group)
<tr><td>{{ \Carbon\Carbon::parse($group['date'])->format('d/m/Y') }}</td><td><button type="button" class="statement-toggle" data-flow-toggle="flow-detail-{{ $index }}" aria-expanded="false" aria-controls="flow-detail-{{ $index }}">{{ config('poseitech.payment_labels.'.$group['method'],$group['method']) }} ▾</button></td><td>{{ $group['count'] }} movimentação(ões)</td><td class="flow-in">{{ $group['incoming']?\App\Services\Tenant::money($group['incoming']):'—' }}</td><td class="flow-out">{{ $group['outgoing']?\App\Services\Tenant::money($group['outgoing']):'—' }}</td><td>{{ \App\Services\Tenant::money($group['difference']) }}</td></tr>
<tr id="flow-detail-{{ $index }}" hidden><td colspan="6"><div class="flow-breakdown">
@foreach(['incoming_details'=>'Entradas','outgoing_details'=>'Saídas'] as $key=>$label)
<section><h3>{{ $label }} · {{ \App\Services\Tenant::money($group[$key==='incoming_details'?'incoming':'outgoing']) }}</h3>
@forelse($group[$key] as $source=>$items)
<details class="flow-source" open><summary>{{ $source }} · {{ \App\Services\Tenant::money(abs($items->sum('amount'))) }}</summary>
@foreach($items as $entry)<div class="flow-entry"><span><time>{{ \Carbon\Carbon::parse($entry['date'])->timezone($company->timezone)->format('H:i:s') }}</time> {{ $entry['description'] }}</span><strong>{{ \App\Services\Tenant::money(abs($entry['amount'])) }}</strong></div>@endforeach
</details>
@empty<p class="muted">Nenhuma movimentação.</p>@endforelse
</section>
@endforeach
</div></td></tr>
@empty<tr><td colspan="6">Sem movimentações neste mês.</td></tr>@endforelse
</tbody><tfoot><tr><th colspan="3">Total do mês</th><td class="flow-in">{{ \App\Services\Tenant::money($incoming) }}</td><td class="flow-out">{{ \App\Services\Tenant::money($outgoing) }}</td><td>{{ \App\Services\Tenant::money($incoming-$outgoing) }}</td></tr></tfoot></table></div></section>
