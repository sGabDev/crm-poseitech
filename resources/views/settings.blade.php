@extends('layout')
@section('title','Configurações')
@section('content')<div class="page-heading">
<div>
<h1>Configurações</h1>
<p>Adapte o PoseiTech à sua operação.</p>
</div>
<span class="badge">Plano {{ $company->plan->name }}</span>
</div>
<section class="card"><h2>Minha senha</h2>@include('components.password-form')</section>
@include('components.modules')
@include('components.catalog-settings')
<details class="card" open>
<summary>Dados da empresa</summary>
<form method="post" enctype="multipart/form-data" action="{{ url('/settings') }}" class="form-grid">@csrf<input type="hidden" name="section" value="company">@foreach(['name'=>'Nome fantasia','legal_name'=>'Razão social','document'=>'CPF/CNPJ','phone'=>'Telefone','whatsapp'=>'WhatsApp com DDI','email'=>'E-mail','address'=>'Endereço'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$company->$key) }}" @required($key==='name') type="{{ $key==='email' ? 'email' : 'text' }}">
</label>@endforeach<label>Fuso horário<select name="timezone">@foreach(['America/Sao_Paulo','America/Manaus','America/Belem','America/Cuiaba','America/Rio_Branco','America/Noronha'] as $zone)<option @selected($company->timezone===$zone)>{{ $zone }}</option>@endforeach</select>
</label>
<label>Logo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
</label>
<label>Moeda<select name="currency">@foreach(['BRL','USD','EUR','GBP'] as $code)<option value="{{ $code }}" @selected($company->currency===$code)>{{ $code }}</option>@endforeach</select>
</label>
<div class="full">
<button>Salvar dados</button>
</div>
</form>
</details>
<details class="card">
<summary>Integração de e-mail (SMTP)</summary>
<p class="muted">Gmail: servidor smtp.gmail.com, usuário com o e-mail completo e senha de aplicativo. Use porta 587 (STARTTLS) ou 465 (SSL/TLS). O remetente deve ser a própria conta ou um endereço autorizado nela. A senha pode ser colada com espaços.</p>
<form method="post" action="{{ url('/settings') }}" class="form-grid">@csrf<input type="hidden" name="section" value="smtp">@foreach(['host'=>'Servidor SMTP','port'=>'Porta','username'=>'Usuário','from'=>'E-mail remetente','from_name'=>'Nome remetente'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$smtp[$key] ?? '') }}" type="{{ $key==='port' ? 'number' : ($key==='from' ? 'email' : 'text') }}" @required($key!=='username')>
</label>@endforeach<label>Senha SMTP<input name="password" type="password" autocomplete="new-password" placeholder="Deixe em branco para manter a senha atual">
</label>
<label>Criptografia<select name="encryption">
<option value="tls" @selected(($smtp['encryption'] ?? '')==='tls')>STARTTLS</option>
<option value="ssl" @selected(($smtp['encryption'] ?? '')==='ssl')>SSL/TLS</option>
</select>
</label>
<div class="full">
<button>Salvar SMTP</button>
</div>
</form>
<form method="post" action="{{ url('/settings/mail') }}" class="separated">@csrf<input type="hidden" name="action" value="test"><button class="secondary">Testar conexão SMTP salva</button></form>
</details>
@include('components.mail-queue')
<details class="card">
<summary>Fidelidade e automações</summary>
<form method="post" action="{{ url('/settings') }}" class="form-grid">@csrf<input type="hidden" name="section" value="loyalty">
<label>Programa<select name="loyalty_mode">
<option value="points" @selected(($company->settings['loyalty_mode'] ?? 'points')==='points')>Pontos por real</option>
<option value="purchases" @selected(($company->settings['loyalty_mode'] ?? '')==='purchases')>Contagem de compras</option>
<option value="cashback" @selected(($company->settings['loyalty_mode'] ?? '')==='cashback')>Cashback</option>
</select>
</label>
<label>Pontos por R$ 1 / percentual de cashback<input type="number" name="loyalty_rate" min="0" max="100" step="0.01" value="{{ $company->settings['loyalty_rate'] ?? 1 }}" required>
</label>
<label class="check full">
<input type="checkbox" name="birthday_automation" value="1" @checked($company->settings['birthday_automation'] ?? false)>Preparar rascunho diário para aniversariantes</label>
<button>Salvar programa</button>
</form>
</details>
<section class="card">
<h2>Equipe e permissões</h2>
<p class="muted">{{ $staff->count() }} de {{ $company->plan->users_limit }} usuários no plano.</p>@foreach($staff as $person)<details class="separated">
<summary>{{ $person->name }} · {{ $person->email }} · {{ $person->active ? 'Ativo' : 'Inativo' }}</summary>@include('components.staff-form',['person'=>$person])
@include('components.staff-actions')
</details>@endforeach<details class="separated">
<summary>+ Adicionar usuário</summary>@include('components.staff-form',['person'=>null])</details>
</section>
<section class="card">
<h2>Auditoria</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Data</th>
<th>Usuário ID</th>
<th>Ação</th>
<th>Registro</th>
<th>IP</th>
</tr>
</thead>
<tbody>@foreach($audit as $log)<tr>
<td>{{ $log->created_at }}</td>
<td>{{ $log->user_id }}</td>
<td>{{ $log->action }}</td>
<td>{{ $log->entity }} #{{ $log->entity_id }}</td>
<td>{{ $log->ip }}</td>
</tr>@endforeach</tbody>
</table>
</div>{{ $audit->links() }}</section>@endsection
