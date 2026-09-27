@extends('layout')
@section('title','Campanhas')
@section('content')<div class="page-heading">
<div>
<h1>Campanhas</h1>
<p>Mensagens relevantes para clientes que autorizaram o contato.</p>
</div>
</div>
<details class="card" open>
<summary>+ Preparar campanha</summary>
<form method="post" action="{{ url('/campaigns') }}" class="form-grid">@csrf<label>Nome interno<input name="name" required maxlength="160">
</label>
<label>Segmento<select name="segment">@foreach(['all'=>'Todos','vip'=>'VIP','new'=>'Novos','inactive'=>'Inativos','birthday'=>'Aniversário próximo','product'=>'Compraram um produto','ticket'=>'Ticket acima de um valor','pending'=>'Com pendências','recurring'=>'Recorrentes'] as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
</label>
<label>Dias sem compra / período de novos<input type="number" name="days" value="30" min="1" required>
</label>
<label>Ticket mínimo ({{ $company->currency ?? 'BRL' }})<input type="number" name="minimum_ticket" step="0.01" min="0" value="0" required>
</label>
<label>Produto<select name="product_id">
<option value="">Selecione se necessário</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
</label>
<label>Assunto<input name="subject" required maxlength="200">
</label>
<label class="full">Mensagem (use {nome} para personalizar)<textarea name="body" rows="5" required maxlength="10000">
</textarea>
</label>
<button>Salvar rascunho</button>
</form>
</details>
<section class="card">
<h2>Campanhas preparadas</h2>@forelse($campaigns as $c)<details class="separated">
<summary>{{ $c->name }} · {{ $c->status }} · {{ $c->recipients }} destinatários</summary>
<h3>{{ $c->subject }}</h3>
<p class="text-block">{{ $c->body }}</p>@if($c->status==='draft')<form method="post" action="{{ url('/campaigns/'.$c->id.'/send') }}" data-confirm="Colocar esta campanha na fila para clientes com consentimento?">@csrf<button>Enviar campanha</button>
</form>@endif</details>@empty<p class="muted">Nenhuma campanha preparada.</p>@endforelse{{ $campaigns->links() }}</section>
<section class="card">
<h2>Histórico de envios</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Destinatário</th>
<th>Assunto</th>
<th>Status</th>
<th>Detalhe</th>
</tr>
</thead>
<tbody>@foreach($logs as $mail)<tr>
<td>{{ $mail->recipient }}</td>
<td>{{ $mail->subject }}</td>
<td>{{ $mail->status }}</td>
<td>{{ $mail->error ?? $mail->sent_at }}</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>@endsection
