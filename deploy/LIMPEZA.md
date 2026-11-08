# Limpeza do projeto

Data: 02/11/2026.

## Resultado

Limpeza baseada em referências do código, rotas, configuração, templates, testes, scripts e documentação. Não houve remoção de código da aplicação, mudança de regras de negócio, interface, rotas ou contratos públicos. As alterações legais que já estavam no workspace foram preservadas.

Aproximadamente **55.24 MiB** removidos (tamanho lógico inicial; não inclui caches recriados e eliminados ao terminar os testes).

## Arquivos e diretórios removidos

- `node_modules/` da raiz: 2.250 arquivos, 57.178.613 bytes. O `package.json` principal não declara dependências e seus scripts usam módulos nativos do Node. Todas as cinco dependências diretas do serviço foram resolvidas em `baileys-server/node_modules/` antes da exclusão. A instalação independente foi preservada e testada após a remoção. O alvo absoluto e a ausência de links/reparse points foram verificados antes de excluir.
- 190 arquivos PHP compilados em `storage/framework/views/`, totalizando 614.088 bytes no inventário inicial. Somente nomes de hash de 32 caracteres foram removidos, preservando `.gitignore`. Views compiladas novamente pelos testes também foram limpas ao encerrar.
- `.phpunit.result.cache`: cache de resultados do PHPUnit, removido depois dos testes.
- Relatórios antigos e saída HTML de prévia, sem consumidores encontrados:

- `.runtime/db-audit.txt`
- `.runtime/db-queries.txt`
- `.runtime/design-preview.html`
- `.runtime/final-php-tests.txt`
- `.runtime/full-test-output.txt`
- `.runtime/inbox-test-final.txt`
- `.runtime/inbox-test-output.txt`
- `.runtime/inbox-test.xml`
- `.runtime/pint-results.txt`
- `.runtime/read-test-output.txt`
- `storage/logs/whatsapp-test-results.xml`

O HTML de prévia pode ser regenerado pelos scripts preservados em `.runtime/`. Os TXT/XML acima eram saídas de análise/testes, não testes executáveis, dados operacionais ou configurações.

## Código, componentes e dependências

- Nenhuma dependência declarada eliminada: `package.json` da raiz já não tem `dependencies` ou `devDependencies`.
- `baileys`, `dotenv`, `pino`, `proper-lockfile` e `qrcode` têm uso direto no serviço e foram mantidas, com o lockfile.
- Dependências Composer e `vendor/` preservados. Ferramentas de desenvolvimento como Sail, Tinker, Pail e Pint podem ser usadas por CLI/autodiscovery, mesmo sem referência em controllers. Não são removidas por ausência de imports na aplicação.
- Nenhum import PHP claramente sem uso identificado na inspeção. Não se confirmou código de aplicação órfão que justificasse exclusão.
- Todos os arquivos de `public/assets/` têm referências. CSS sobreposto não foi consolidado porque a ordem de cascata participa do comportamento visual.
- Os templates `emails.receipt-itens`, `receipt-pagamentos`, `receipt-portal` e `receipt-resumo` são carregados dinamicamente por `ReceiptHtml`; mantidos.
- Páginas `errors/*.blade.php` idênticas são pontos de resolução dinâmica de erros do Laravel; mantidas.
- Commands, providers e middlewares são descobertos/registrados pelo framework; mantidos.
- `services/whatsapp/` contém wrappers de compatibilidade, e não outra implementação do serviço; preservado para evitar quebra de caminhos usados externamente.
- Não houve reorganização de diretórios: os caminhos atuais têm consumidores ou podem ser pontos de integração externos.

## Ajuste de teste existente

`tests/frontend.cjs` falhava antes da limpeza por simular um navegador sem `document.addEventListener`, `window.matchMedia` e o elemento `#pending-row span`, usados pelo código atual. Atualizada somente a simulação, preservando as assertions existentes. A aplicação não foi alterada para satisfazer o teste.

## Validação após a limpeza

| Verificação | Resultado |
| --- | --- |
| PHPUnit completo | 161 testes, 1.564 assertions, aprovados |
| Baileys independente (`node baileys-server/tests.mjs`) | 16 testes aprovados, incluindo inicialização real em localhost e autenticação com credenciais de teste |
| Polling WhatsApp | 2 testes aprovados |
| Inbox frontend | 6 testes aprovados |
| Formatação WhatsApp | 2 testes aprovados |
| Frontend geral (`node tests/frontend.cjs`) | Busca, máscara, pagamento, saldos, estoque e cupom aprovados |
| Implantação (`php tests/deployment.php`) | Aprovada usando fixture isolada, sem alterar `.env` do projeto |
| Sintaxe (`node scripts/check.cjs`) | PHP e JavaScript válidos |
| `git diff --check` | Sem erros de whitespace |

Testes que iniciam subprocessos precisaram de execução fora da restrição de spawn do ambiente (EPERM na primeira tentativa); passaram na execução autorizada. Não houve conexão real de WhatsApp, envio a clientes, migração de banco operacional ou implantação. Teste de carga `tests/load/database.k6.js` preservado e não executado: requer alvo e ambiente dimensionados para carga.

## Itens preservados para validação manual

- `baileys-server.zip`: citado pelos guias de implantação. Conferir se corresponde aos fontes antes do próximo envio; não presumir que o pacote seja descartável ou atualizado.
- Logs em `storage/logs/`, inclusive os datados de outubro: podem ser necessários para diagnóstico, incidentes e retenção. Definir prazo e arquivamento antes de excluir. Não foram truncados logs de processos ativos.
- Scripts `render-*-preview.php`, `inbox-preview-data.js` e `whatsapp-inspect.php` em `.runtime/`: utilitários locais de prévia/diagnóstico que podem ser usados manualmente; sem prova de desuso, preservados. Não publicar `.runtime/` como conteúdo web.
- Documentos idênticos `deploy/WHATSAPP-ATENDIMENTO.md` e `baileys-server/ATENDIMENTO.md`: o segundo acompanha o pacote standalone, podendo ser necessário fora deste repositório.
- `composer.phar`: ferramenta local mencionada no README, preservada.
- Uploads, bancos, sessões, cache funcional, dados e credenciais Baileys, `.env`, `.git`, configurações, migrations, seeds e infraestrutura preservados. Esses diretórios não são resíduos de desenvolvimento por definição.

A aprovação dos testes valida os fluxos cobertos; integrações externas e produção continuam dependentes de seu ambiente real.
