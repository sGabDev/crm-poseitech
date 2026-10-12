@can('manage-company')
@php($mp = $company->mercadopago ?? [])
<details class="card">
<summary>Mercado Pago · links de pagamento</summary>
<p class="muted">Receba vendas e dívidas com confirmação automática. Credenciais exclusivas desta empresa, armazenadas com criptografia.</p>
<form method="post" action="{{ url('/settings/mercadopago') }}" class="form-grid">@csrf
<label class="check full"><input type="checkbox" name="enabled" value="1" @checked($mp['enabled'] ?? false)>Ativar pagamentos Mercado Pago</label>
<label>Ambiente<select name="mode"><option value="test" @selected(($mp['mode'] ?? 'test')==='test')>Teste · sem baixa financeira</option><option value="production" @selected(($mp['mode'] ?? '')==='production')>Produção · pagamentos reais</option></select></label>
<label>Conta conectada<input readonly value="{{ $mp['collector_id'] ?? 'Ainda não verificada' }}"></label>
<label>Access Token<input type="password" name="access_token" autocomplete="new-password" placeholder="{{ empty($mp['access_token']) ? 'Cole o Access Token da aplicação' : 'Salvo · deixe em branco para manter' }}"></label>
<label>Assinatura secreta do webhook<input type="password" name="webhook_secret" autocomplete="new-password" placeholder="{{ empty($mp['webhook_secret']) ? 'Cole a assinatura secreta' : 'Salva · deixe em branco para manter' }}"></label>
<label class="full">URL do webhook<input readonly value="{{ url('/integrations/mercadopago/'.$company->id.'/webhook') }}"></label>
<p class="muted full">No painel de desenvolvedores do Mercado Pago, configure esta URL para notificações de <strong>Pagamentos</strong>. Use o mesmo ambiente das credenciais. O CRM precisa de domínio HTTPS público e agendador ativo. Em teste, a aprovação não quita vendas reais.</p>
<div class="full"><button>Salvar e verificar conta</button> <a href="https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/payment-notifications" target="_blank" rel="noopener">Configurar notificações ↗</a></div>
</form></details>
@endcan
