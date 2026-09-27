<section class="card">
<h2>Configurações globais</h2>
<form method="post" action="{{ url('/admin/settings') }}" class="form-grid">@csrf<label class="check">
<input type="checkbox" name="registration_enabled" value="1" @checked(($platformSettings['registration_enabled'] ?? '1')==='1')>Permitir cadastro público de empresas</label>
<label>Dias de avaliação<input type="number" min="1" max="365" name="trial_days" value="{{ $platformSettings['trial_days'] ?? 14 }}" required>
</label>
<label>E-mail de suporte<input type="email" name="support_email" value="{{ $platformSettings['support_email'] ?? '' }}" required>
</label>
<button>Salvar configurações globais</button>
</form>
</section>
<section class="card">
<h2>Uso da plataforma</h2>
<p>{{ $newCompanies }} empresas cadastradas nos últimos 30 dias.</p>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Empresa</th>
<th>Vendas registradas</th>
<th>Volume bruto registrado</th>
</tr>
</thead>
<tbody>@foreach($usage as $row)<tr>
<td>{{ $row->name }}</td>
<td>{{ $row->sales }}</td>
<td>{{ \App\Services\Tenant::money($row->volume) }}</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>
