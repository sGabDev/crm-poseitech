<footer class="legal-footer" aria-label="Informações legais">
<nav aria-label="Políticas do site">
<a href="{{ url('/legal/privacidade') }}">Privacidade e LGPD</a>
<a href="{{ url('/legal/termos') }}">Termos de serviço</a>
<a href="{{ url('/legal/reembolso') }}">Cancelamento e reembolso</a>
<a href="{{ url('/legal/cookies') }}">Cookies</a>
<button type="button" data-privacy-open aria-controls="privacy-panel" aria-expanded="false" hidden>Preferências de cookies</button>
</nav>
<p>PoseiTech CRM · {{ config('legal.name') ?: 'Identificação legal da operadora pendente de cadastro' }}</p>
@if(config('legal.document'))<p>CPF/CNPJ: {{ config('legal.document') }}</p>@endif
@if(config('legal.address'))<p>{{ config('legal.address') }}</p>@endif
@if(config('legal.email'))<a href="mailto:{{ config('legal.email') }}">{{ config('legal.email') }}</a>@endif
</footer>
<section class="privacy-panel" id="privacy-panel" data-privacy-panel role="region" aria-labelledby="privacy-heading" hidden>
<div class="privacy-heading"><span class="privacy-icon" aria-hidden="true">◈</span><div><span class="privacy-eyebrow">SUA PRIVACIDADE</span><h2 id="privacy-heading" tabindex="-1">Cookies e armazenamento local</h2></div><button type="button" class="privacy-dismiss" data-privacy-dismiss aria-label="Fechar aviso de cookies">×</button></div>
<p>Usamos cookies de sessão e segurança e armazenamento local do carrinho para operar o serviço. Não há cookies opcionais de análise ou publicidade habilitados. Esses recursos necessários não dependem de consentimento.</p>
<details class="privacy-details"><summary>O que fica no seu navegador?</summary><dl><dt>Sessão e segurança <span>Sempre necessários</span></dt><dd>Mantêm seu acesso e ajudam a proteger as operações.</dd><dt>Carrinho e preferências <span>Armazenamento local</span></dt><dd>Preservam os itens do carrinho e o registro de leitura deste aviso.</dd><dt>Análise e publicidade <span>Não habilitados</span></dt><dd>O site não utiliza cookies opcionais dessas categorias.</dd></dl></details>
<div class="privacy-actions"><a href="{{ url('/legal/cookies') }}">Consultar finalidades e controles</a><button type="button" data-privacy-close>Entendi · fechar aviso</button></div>
<small class="privacy-note">Você pode reabrir este aviso a qualquer momento pelo rodapé.</small>
</section>
