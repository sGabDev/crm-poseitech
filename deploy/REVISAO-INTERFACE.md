# Revisão incremental da interface — 05/11/2026

Esta etapa foi feita por leitura do código, compilação Blade e testes locais. Não representa conclusão da auditoria completa nem validação visual de todas as telas. O Computer Use interrompeu as tentativas anteriores por não conseguir identificar a URL do Chrome. Nesta etapa não houve nova tentativa de automação do navegador.

## Problemas confirmados e corrigidos

| Prioridade | Problema | Correção |
| --- | --- | --- |
| Alta | Histórico do suporte podia ficar bloqueado por uma requisição sem resposta. | Cancelamento após 20 segundos, limpeza do timer e liberação da próxima tentativa. |
| Média | Escape devolvia o foco ao campo de busca e esse foco reabria os resultados. | A lista fecha depois da restauração do foco; Escape não propaga para outros controles. |
| Média | Adicionais apagados reapareciam na edição de produtos após erro de validação. | Diferenciar ausência de dados anteriores de um valor anterior deliberadamente vazio. |
| Média | Caixas desmarcadas podiam recuperar o valor salvo após erro de validação. | Enviar explicitamente zero quando a caixa não estiver marcada. |
| Média | Movimentação de estoque perdia os valores informados após erro. | Restaurar produto, tipo, quantidade e motivo usando os dados anteriores da requisição. |
| Baixa | Busca sem resultados mostrava mensagem de primeiro cadastro. | Estado vazio específico para filtros, com ação para limpar a consulta. |
| Baixa | Colspan incorreto em cadastros e contas somente para leitura. | Calcular o número real de colunas. |
| Baixa | Estoque sem produtos ou movimentos não tinha mensagem explicativa. | Estados vazios específicos. |
| Baixa | Regiões de tabelas não tinham o mesmo foco visível dos demais controles. | Estender o estilo compartilhado de foco às tabelas roláveis. |
| Baixa | Operações em andamento não tinham estilo compartilhado para o estado ocupado. | Cursor de progresso e opacidade nos botões marcados pelo controle de envio. |

## Arquivos desta etapa

- `public/assets/app.js`
- `public/assets/support-chat.js`
- `public/assets/minimal.css`
- `resources/views/record-form.blade.php`
- `resources/views/records.blade.php`
- `resources/views/stock.blade.php`
- `resources/views/components/accounts.blade.php`
- `scripts/check.cjs`
- `tests/frontend.cjs`
- `tests/support-chat.cjs` (novo)
- `tests/Feature/CommerceTest.php`

Alterações anteriores existentes no diretório foram preservadas. Nenhuma alteração em `.env`, esquema de banco ou contrato de API foi feita nesta etapa.

## Validação executada

- `npm test`: passou. Inclui sintaxe PHP e JavaScript, testes de interface, privacidade, UX, suporte e WhatsApp do navegador, além do Laravel.
- Laravel: **180 testes passaram; 1.675 assertions**.
- Testes novos de edição de produtos e estado vazio filtrado: **2 passaram; 8 assertions**, também incluídos na suíte completa.
- Teste JavaScript do suporte: requisição pendente cancelada, erro apresentado, histórico liberado, nova tentativa e limpeza dos timers passaram.
- Teste JavaScript de Escape nos seletores pesquisáveis: passou.
- `php artisan view:cache`: compilação de todas as views concluída; `view:clear` executado depois.
- `git diff --check`: sem erros de whitespace; Git informou apenas avisos de normalização CRLF/LF em arquivos existentes.

Os testes de renderização existentes incluem dashboard, oportunidades, cadastros, vendas, clientes, caixa, fiados, estoque, pedidos, relatórios, busca, alertas, campanhas, configurações, catálogo, recibo e exportações CSV. Renderizar uma resposta HTTP com sucesso não comprova comportamento ou aparência no navegador.

## Pendente

- Inspecionar todas as telas visualmente, incluindo rolagem, sobreposições, zoom, resoluções móveis e tablets.
- Verificar console, teclado e leitores de tela em navegador real.
- Conferir impressão e PDF visualmente; as verificações de CSV são automatizadas.
- Exercitar integrações reais de SMTP, WhatsApp e pagamentos em ambiente autorizado; esta etapa não enviou mensagens nem realizou pagamentos.
- Prosseguir com a auditoria aprofundada dos demais módulos; nenhuma declaração de revisão completa de cada arquivo é feita aqui.
