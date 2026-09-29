@if($company->enabled('catalog'))
<section class="card"><h2>Seu catálogo público</h2><p><a href="{{ url('/catalog/'.$company->slug) }}" target="_blank" rel="noopener">{{ url('/catalog/'.$company->slug) }}</a></p>
<form method="post" action="{{ url('/settings') }}" class="form-grid">@csrf<input type="hidden" name="section" value="catalog">
<label>Título da vitrine<input name="catalog_title" maxlength="120" value="{{ old('catalog_title',$company->settings['catalog_title'] ?? '') }}"></label>
<label>Cor da marca<input type="color" name="catalog_color" value="{{ old('catalog_color',$company->settings['catalog_color'] ?? '#155e75') }}"></label>
<label>Apresentação<textarea name="catalog_description" maxlength="1000">{{ old('catalog_description',$company->settings['catalog_description'] ?? '') }}</textarea></label>
<label class="check"><input type="checkbox" name="catalog_checkout" value="1" @checked(old('catalog_checkout',$company->settings['catalog_checkout'] ?? false))>Receber pedidos pelo catálogo</label>
<p class="muted">O cliente envia o carrinho para conferência da equipe. Frete e pagamento são combinados no atendimento. Logo, endereço e WhatsApp usam os dados da empresa.</p><button>Salvar catálogo</button>
</form></section>@endif
