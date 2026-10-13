# Produção e SEO técnico

## Publicação

Configure a instalação de produção:

~~~dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://crm.seudominio.com.br
SESSION_SECURE_COOKIE=true
SEO_INDEXING=true
SEO_SITE_NAME="PoseiTech CRM"
SEO_DESCRIPTION="Gestão de vendas, clientes, atendimento e pedidos em um só lugar."
~~~

Preserve APP_KEY. Não publique .env, banco, logs, testes, .runtime ou credenciais. O document root deve apontar somente para public/ (ou public_html/ na estrutura de hospedagem suportada).

Após publicar:

~~~sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan poseitech:production-check
~~~

O diagnóstico não revela credenciais e aponta configurações pendentes, mas não verifica infraestrutura externa. Reinicie workers e mantenha o cron. Para várias empresas/servidores, siga [ENVIOS-E-ESCALA.md](ENVIOS-E-ESCALA.md).

No servidor/proxy:

- Configure certificado TLS válido, redirecionamento HTTP → HTTPS e um único domínio canônico, coerente com APP_URL.
- Se houver proxy reverso, configure apenas os IPs confiáveis no Laravel/servidor e encaminhe corretamente o protocolo HTTPS. Não confie indiscriminadamente nos cabeçalhos recebidos da internet.
- Configure HSTS após confirmar HTTPS. Avalie todos os subdomínios antes de usar includeSubDomains ou preload.
- Apache: permita .htaccess, mod_rewrite, mod_deflate e mod_expires. Compressão e cache estático já estão declarados em public/.htaccess.
- Nginx: configure try_files para encaminhar rotas ao index.php, compressão e cache de CSS/JS/imagens. /robots.txt deve passar pelo Laravel; remova cópias estáticas antigas.
- HTML personalizado permanece private, no-store. Não faça cache público do catálogo no CDN. Robots e sitemap não usam sessão/cookies e têm cache de cinco minutos.

## Indexação e compartilhamento

- /sitemap.xml é um índice; /sitemaps/catalogs-1.xml e seguintes listam até mil empresas por arquivo. Incluem apenas catálogos ativos, com assinatura válida, módulo habilitado e permissão de indexação. Não são inventadas datas de atualização.
- Não entram no sitemap login, CRM, pedidos privados, comprovantes ou pagamentos.
- A empresa pode editar título, descrição e permissão de indexação em **Configurações → Catálogo e automações**. Alterações no sitemap podem levar cinco minutos por causa do cache.
- Catálogos anônimos recebem canonical sem parâmetros, descrição, Open Graph, Twitter Card e JSON-LD de Store/WebPage/OfferCatalog. A marcação inclui até cem ofertas visíveis para limitar o peso do HTML, com preços e disponibilidade reais, sem avaliações inventadas.
- O compartilhamento usa banner da empresa, logo ou imagem padrão de 1200×630. Escolha banner horizontal legível. As redes sociais podem manter cache da prévia.
- Páginas privadas, erros, checkout e catálogos personalizados por sessão usam noindex. Cadastro por CPF não cria página pública do cliente.
- Em homologação, mantenha SEO_INDEXING=false: robots bloqueia rastreamento, páginas usam noindex e sitemap fica vazio.
- Robots não substitui autenticação nem remove URLs já indexadas. Caso existam URLs privadas antigas nas buscas, use a ferramenta de remoção do buscador e mantenha autenticação/cabeçalhos corretos.

## Verificação no domínio definitivo

1. Confira /robots.txt, /sitemap.xml e o domínio das URLs. Verifique a propriedade e envie o sitemap ao Google Search Console e Bing Webmaster Tools.
2. Confira canonical, robots, X-Robots-Tag e CSP em um catálogo anônimo, um catálogo desativado para buscas e páginas privadas.
3. Valide XML e dados estruturados. Dados estruturados não garantem indexação nem resultados enriquecidos.
4. Teste compartilhamento, favicon, teclado, foco dos diálogos, contraste, zoom a 200% e telas pequenas. Há link para pular ao conteúdo, nomes acessíveis nos diálogos e melhorias no carregamento das imagens.
5. Execute Lighthouse/PageSpeed e monitore Core Web Vitals em dispositivos reais. **Não houve medição em produção nem auditoria completa de WCAG nesta implementação.**
6. Confira as páginas 403, 404, 405, 419, 422, 429 e 500–504. Preservam o status HTTP e não exibem stack traces com APP_DEBUG=false. Falhas do proxy/servidor antes do PHP precisam de páginas configuradas nele.

Referências: [sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview), [noindex](https://developers.google.com/search/docs/crawling-indexing/block-indexing), [canonical](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls), [Store](https://schema.org/Store) e [OfferCatalog](https://schema.org/OfferCatalog).
