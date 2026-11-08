# Revisão de privacidade, consumo e acessibilidade

Data: 02/11/2026. Escopo: revisão estática do código Laravel, Blade, CSS e JavaScript local. Não representa parecer jurídico, certificação LGPD ou avaliação completa de acessibilidade. Nenhum servidor de produção, conta de analytics, DNS, gerenciador de tags, contrato ou fornecedor foi inspecionado.

## Implementado

- Páginas públicas `/legal/privacidade`, `/legal/termos`, `/legal/cookies` e `/legal/reembolso`, com links em todas as telas que usam o layout compartilhado, inclusive atendimento imersivo.
- E-mail de suporte, reembolso e privacidade: suporte@poseitech.com.br, informado pelo responsável. Confirmar recebimento e monitoramento da caixa.
- CPF fornecido não foi incluído no código nem na configuração, por escolha do responsável.
- Aviso de armazenamento necessário com reabertura, fechamento por teclado e persistência local de 180 dias. Não é aceite de marketing nem consentimento genérico à LGPD.
- Nenhum GA, GTM, Meta Pixel, Hotjar ou Clarity encontrado nos scripts locais. `App/Services/Analytics.php` calcula estatísticas comerciais internas. CSP restringe scripts a mesma origem e nonce; não foi ampliada para terceiros. JSON-LD de SEO não é tracker.
- Carrinho local, cookies de autenticação/CSRF e lembrar de mim descritos. Nenhum rastreador opcional foi adicionado. Antes de adicionar, implementar bloqueio prévio, recusa em destaque equivalente, escolha granular e revogação com limpeza dos identificadores.
- Foco visível, suporte à redução de movimento, ajustes de contraste em texto secundário e placeholders e retirada do menu móvel fechado da ordem de foco com `inert`.
- Imagens Blade revisadas já possuíam alt; capa decorativa mantém alt vazio. Não foram inventadas descrições de imagens não vistas. Banner promocional usa título da loja: revisar se descreve de fato o conteúdo visual e cadastrar descrição específica quando necessário.

## Pendências antes de comercializar

1. Completar nome civil/razão social, documento legalmente exigido e endereço comercial, via `LEGAL_COMPANY_NAME`, `LEGAL_COMPANY_DOCUMENT`, `LEGAL_COMPANY_ADDRESS`. PoseiTech é apenas o nome informado. A decisão de não publicar o CPF deixa a identificação incompleta; avaliar com contador/advogado a formalização e a divulgação exigida pelo Decreto 7.962/2013. Não colocar documento de exemplo.
2. Validar ofertas, preços, renovação, confirmação de contratação, entrega de contrato, cancelamento e processamento real de estornos. A página orienta e-mail, mas não automatiza estorno nem confirma recebimento por e-mail. O atendimento deve cumprir as obrigações e prazos; o direito de arrependimento não exige justificativa ou ausência de uso.
3. Documentar inventário e bases legais por finalidade, operadores/suboperadores, hospedagem, região, SMTP, Mercado Pago e WhatsApp/Meta. Celebrar contratos e regularizar transferências internacionais conforme regulamentação da ANPD. Ajustar política aos fornecedores e práticas efetivas.
4. Estabelecer retenção por categoria, descarte verificável, backups e restauração que respeite eliminações. Existem exportação e anonimização de clientes, mas mensagens WhatsApp, chamados, anexos, pedidos de catálogo, registros de pagamento, auditorias de outras entidades e backups precisam de análise específica; não prometer exclusão total.
5. Instituir fluxo de titulares, verificação proporcional de identidade, confirmação/acesso e resposta, revogação de marketing e registro dos pedidos; definir responsável por privacidade e avaliar obrigação de encarregado. A simples publicação do e-mail não implementa o processo.
6. Validar consentimento de campanhas em todos os canais, origem e evidência da autorização, cancelamento facilitado e supressão de destinatários revogados em filas já criadas. Não presumir consentimento a partir de compra ou cadastro.
7. Revisar uso de conector WhatsApp não oficial (Baileys), termos da Meta, risco de bloqueio de conta e exposição de mensagens. Preferir integração oficial quando adequada. Auditar anexos, sessões do conector, credenciais e retenção de chats.
8. Confirmar HTTPS, debug desativado, cookies seguros, isolamento entre empresas, mínimo privilégio, segredos, atualizações, backup testado e resposta a incidentes. Não houve pentest. Definir comunicação de incidentes de acordo com a regulamentação da ANPD.
9. Cada loja do catálogo deve exibir identificação legal e contato do vendedor, frete, prazo, disponibilidade, condições de devolução e atendimento antes da compra. As páginas da plataforma não substituem as obrigações de cada vendedor.
10. Verificar autoria/licenças de imagens, fontes e textos, marcas, alegações comerciais e direitos de terceiros, inclusive conteúdo gerado por IA. A origem por IA não elimina responsabilidade.

## Auditoria de acessibilidade e limites

Objetivo de referência: WCAG 2.2 AA. O layout já tinha idioma pt-BR, link para pular conteúdo, main focável, rótulos em vários formulários e diálogos nativos no catálogo. A revisão de código e as correções compartilhadas não garantem navegação integral em todos os módulos.

Executar avaliação real em desktop e celular, a 200% e 400% de zoom: login, cadastro, recuperação, catálogo, sacola, pagamento, relatórios, suporte e inbox. Percorrer com Tab/Shift+Tab/Enter/Espaço/Escape; verificar foco em diálogos, menus, erros e conteúdo atualizado. Testar NVDA/Firefox ou Chrome e VoiceOver/Safari. Medir contraste de todos os estados e temas (4,5:1 texto comum; 3:1 texto grande e controles relevantes), incluindo loja e inbox que possuem CSS próprio. Conferir tabelas, ícones sem nome, mensagens ao vivo e alternativas para gráficos. Ainda não executado: auditoria visual completa, leitor de tela ou ferramenta axe sobre todos os fluxos.

## Fontes oficiais

- LGPD: https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm
- Guia ANPD de cookies: https://www.gov.br/anpd/pt-br/centrais-de-conteudo/materiais-educativos-e-publicacoes/guia_orientativo_cookies_e_protecao_de_dados_pessoais
- CDC, art. 49: https://www.planalto.gov.br/ccivil_03/leis/l8078compilado.htm
- Comércio eletrônico: https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2013/decreto/d7962.htm
- LBI, art. 63: https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2015/lei/l13146.htm
- WCAG 2.2: https://www.w3.org/TR/WCAG22/

## Validação executada

- PHPUnit completo: 161 testes, 1.564 assertions, sem falhas (inclui os três novos testes das páginas legais).
- Após incluir identificação do vendedor no catálogo: 21 testes e 212 assertions de páginas legais, SEO e checkout passaram.
- PHP lint de configuração/rotas e `node --check` dos scripts alterados passaram; `git diff --check` sem erros.
- O agregador `node scripts/check.cjs` encontrou EPERM ao criar subprocesso PHP neste ambiente; PHP e PHPUnit foram executados diretamente. A primeira execução via `artisan test` detectou um problema de acentuação no texto de reembolso, corrigido antes das execuções aprovadas do PHPUnit direto.
- Contraste calculado dos pares novos: conferir resultados junto da avaliação visual de fundos e estados efetivos. Não foi executada inspeção visual nem avaliação com leitor de tela.
- Dados legais já cadastrados nas empresas são exibidos nos catálogos e na página de pagamento; campos ausentes ainda precisam ser preenchidos pelo vendedor.
