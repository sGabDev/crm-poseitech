@extends('layout')
@section('title','Super Admin PoseiTech')
@section('content')<div class="page-heading">
<div>
<div class="eyebrow">ADMINISTRAÇÃO DA PLATAFORMA</div>
<h1>PoseiTech SaaS</h1>
<p>Empresas, assinaturas, planos e suporte.</p>
</div>
</div>
<div class="kpi-grid">@foreach($counts as $label=>$count)<div class="kpi">
<span>{{ $label }}</span>
<strong>{{ $count }}</strong>
</div>@endforeach</div>
<section class="card">
<h2>Empresas</h2>@foreach($companies as $co)<details class="separated">
<summary>{{ $co->name }} · {{ $co->plan->name }} · {{ $co->status }}</summary>
<form method="post" action="{{ url('/admin/companies/'.$co->id) }}" class="form-grid">@csrf<label>Nome<input name="name" value="{{ $co->name }}" required>
</label>
<label>Plano<select name="plan_id">@foreach($plans as $p)<option value="{{ $p->id }}" @selected($co->plan_id===$p->id)>{{ $p->name }}</option>@endforeach</select>
</label>
<label>Status<select name="status">@foreach(['active'=>'Ativa','inactive'=>'Inativa','blocked'=>'Bloqueada'] as $k=>$v)<option value="{{ $k }}" @selected($co->status===$k)>{{ $v }}</option>@endforeach</select>
</label>
<label>Assinatura até (em branco: sem prazo)<input type="date" name="subscription_until" value="{{ $co->subscription_until?->toDateString() }}">
</label>
<button>Salvar empresa</button>
</form>
<p class="separated">Módulos: {{ implode(', ',array_map(fn($key)=>config('poseitech.modules.'.$key),$co->modules)) }}</p>
<form method="post" action="{{ url('/admin/support/'.$co->id) }}" class="filters">@csrf<label>Motivo do suporte<input name="reason" minlength="5" required>
</label>
<button class="secondary">Entrar como suporte</button>
</form>
</details>@endforeach{{ $companies->links() }}</section>
<details class="card">
<summary>+ Criar empresa</summary>
<form method="post" action="{{ url('/admin/companies') }}" class="form-grid">@csrf<label>Nome da empresa<input name="name" required>
</label>
<label>Plano<select name="plan_id">@foreach($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
</label>
<input type="hidden" name="status" value="active">
<label>Nome do administrador<input name="owner_name" required>
</label>
<label>E-mail do administrador<input type="email" name="owner_email" required>
</label>
<label>Senha inicial<input type="password" name="owner_password" minlength="10" required autocomplete="new-password">
</label>
<label>Assinatura até<input type="date" name="subscription_until">
</label>
<button>Criar empresa</button>
</form>
</details>
<section class="card">
<h2>Planos</h2>@foreach($plans->concat([null]) as $p)<details class="separated">
<summary>{{ $p ? $p->name.' · '.\App\Services\Tenant::money($p->price) : '+ Criar plano' }}</summary>
<form method="post" action="{{ url('/admin/plans'.($p ? '/'.$p->id : '')) }}" class="form-grid">@csrf<label>Nome<input name="name" value="{{ $p->name ?? '' }}" required>
</label>
<label>Preço mensal (R$)<input type="number" step="0.01" min="0" name="price" value="{{ ($p->price ?? 0)/100 }}" required>
</label>@foreach(['users_limit'=>'Usuários','customers_limit'=>'Clientes','products_limit'=>'Produtos','campaigns_limit'=>'Campanhas','storage_mb'=>'Armazenamento (MB)'] as $key=>$label)<label>{{ $label }}<input type="number" name="{{ $key }}" min="{{ $key==='campaigns_limit' ? 0 : 1 }}" value="{{ $p->$key ?? 10 }}" required>
</label>@endforeach<div class="full module-grid">@foreach(config('poseitech.modules') as $key=>$label)<label class="check">
<input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key,$p->modules ?? []))>{{ $label }}</label>@endforeach</div>
<button>Salvar plano</button>
</form>
</details>@endforeach</section>
<section class="card">
<h2>Últimas ações da plataforma</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Data</th>
<th>Empresa</th>
<th>Usuário</th>
<th>Ação</th>
</tr>
</thead>
<tbody>@foreach($logs as $log)<tr>
<td>{{ $log->created_at }}</td>
<td>{{ $log->company_id }}</td>
<td>{{ $log->user_id }}</td>
<td>{{ $log->action }}</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>@include('components.platform-settings')
@endsection
