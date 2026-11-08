<section class="seller-identity" aria-label="Identificação do vendedor">
<h2>Fornecedor desta loja</h2>
<p>{{ $company->legal_name ?: $company->name }}</p>
@if($company->document)<p>CPF/CNPJ: {{ $company->document }}</p>@endif
@if($company->address)<p>{{ $company->address }}</p>@endif
@if($company->email)<p><a href="mailto:{{ $company->email }}">{{ $company->email }}</a></p>@endif
@if($company->phone)<p>Telefone: {{ $company->phone }}</p>@endif
<p>Para entrega, cancelamento e reembolso desta compra, contate o fornecedor acima. <a href="{{ url('/legal/reembolso') }}">Consulte seus direitos e orientações</a>.</p>
</section>
