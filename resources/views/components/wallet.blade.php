<section class="card"><h2>Saldo da conta</h2><strong>{{ \App\Services\Tenant::money($walletEntries->sum('amount')) }}</strong>
<p>Depósitos disponíveis para futuras compras. Benefícios de fidelidade são registrados separadamente.</p>
@foreach($walletEntries as $entry)<div class="metric-row"><span>{{ \Carbon\Carbon::parse($entry->created_at)->timezone($company->timezone)->format('d/m/Y H:i:s') }} · {{ $entry->description }}</span><strong>{{ \App\Services\Tenant::money($entry->amount) }}</strong></div>@endforeach</section>
