<form method="post" action="{{ url('/customer-deposits') }}" id="deposit-form" class="card stack" data-currency="{{ $company->currency }}" hidden>@csrf
<input type="hidden" name="operation" value="deposit"><input type="hidden" name="request_key" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
<h2>Depósito na conta do cliente</h2><p>O valor abate primeiro os fiados em aberto. O excedente fica disponível para próximas compras. Não movimenta produtos nem gera uma venda.</p>
<label>Cliente<div class="search-picker"><input type="search" class="search-input" placeholder="Digite nome, telefone ou CPF" autocomplete="off" required><div class="search-results" hidden></div></div>
<select name="customer_id" data-search-select="Nome, telefone ou CPF" hidden><option value="">Selecione</option>@foreach($customers as $c)<option value="{{ $c->id }}" data-search="{{ $c->name }} {{ $c->phone }} {{ $c->document }}" data-debt="{{ $debts[$c->id] ?? 0 }}" data-wallet="{{ $wallets[$c->id] ?? 0 }}" @selected(old('customer_id')==$c->id)>{{ $c->name }} · {{ $c->phone }}</option>@endforeach</select></label>
<label>Valor recebido ({{ $company->currency }})<input type="number" name="amount" value="{{ old('amount') }}" step="0.01" min="0.01" required></label>
<label>Forma de pagamento<select name="method">@foreach(config('poseitech.methods') as $k=>$v)<option value="{{ $k }}" @selected(old('method')===$k)>{{ $v }}</option>@endforeach</select></label>
<div id="deposit-preview" class="muted"></div><button>Registrar depósito</button>
</form>
