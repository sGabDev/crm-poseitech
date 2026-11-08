# Redução de novas conexões MySQL — 08/11/2026

## Escopo e evidências

Revisão de código e testes locais isolados. Nenhuma conexão ao MySQL da Hostinger foi feita nesta revisão; não foram executados diagnóstico remoto, carga, migrations ou comandos que alterem o banco real. Os resultados de MySQL registrados anteriormente em `MYSQL.md` são históricos, não uma medição desta entrega. O limite informado continua sendo **500 novas conexões por hora**, distinto de conexões simultâneas e de consultas por hora.

Não há `AGENTS.md` na raiz, nos diretórios pais examinados ou no código do projeto. Foram examinados README, guias de MySQL/Hostinger, configurações, entrada HTTP, providers, middleware, controllers, serviços, rotas, agendador, assets e testes existentes. Já havia alterações locais antes desta revisão; elas foram preservadas.

## Host confirmado

- `.env` do ambiente local: `DB_CONNECTION=mysql`, `DB_HOST=srv801.hstgr.io`, `DB_PORT=3306`. Não foi alterado. Não havia cache de configuração Laravel em `bootstrap/cache/config.php` no momento da inspeção, nem `DB_URL` definido no `.env`.
- O responsável confirmou nesta sessão que o PHP do site publicado e o banco estão na mesma hospedagem e que o host usado no site é **`localhost`**. O hostname acima é utilizado para acesso remoto pelo ambiente local.
- O exemplo `deploy/hostinger.env.example` e o fallback de criação de um `.env` novo em `scripts/hostinger.php` passaram a usar `localhost`. O instalador continua preservando `.env` já existente. Não houve acesso ao hPanel nem alteração do servidor publicado.
- A documentação da Hostinger indica `localhost` para conexão local: [porta e conexões locais](https://www.hostinger.com/support/1583223-what-mysql-port-is-used-at-hostinger/). Isso não prova que a quota horária deixe de ser aplicada. Não substitua o host remoto do desenvolvimento por `localhost`, pois isso apontaria para a máquina de desenvolvimento.

## Onde conexões são abertas e reutilizadas

O arquivo compartilhado já existe: **`config/database.php`**. `AppServiceProvider::register()` registra os adaptadores MySQL/MariaDB, e o `DatabaseManager` do Laravel mantém um objeto lazy por nome de conexão. Eloquent, Query Builder, `DB::transaction`, serviços e arquivos Blade incluídos usam esse mesmo gerenciador. Não foi acrescentado outro arquivo com um PDO paralelo.

A criação física do PDO fica em `vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php`, acionada pelo `ConnectionFactory`/`MySqlConnector` do Laravel na primeira operação SQL. Não existem instâncias manuais de PDO nem uso de MySQLi no código da aplicação. Os `new PDOException` dos testes simulam erros, não abrem conexões. Não há configuração de conexões separadas para leitura e escrita, nem conexão por empresa. A configuração oferece outros drivers do framework, mas o selecionado no ambiente examinado é MySQL.

Pontos explícitos de acesso ao gerenciador/conexão:

| Arquivo | Finalidade |
| --- | --- |
| `app/Providers/AppServiceProvider.php` | Resolvers, observação de duração e liberação ao terminar a execução. Registrar o resolver não abre um socket. |
| `app/Database/ConnectionLifecycle.php` | Rollback de transações remanescentes e fechamento **ao terminar**; percorre apenas conexões já registradas. |
| `app/Database/MySqlConnection.php`, `MariaDbConnection.php` | Adaptadores existentes que impedem repetir consultas após perder a confirmação. O conector do framework ainda pode tentar restabelecer uma conexão inicial que falhou. |
| `app/Services/DeliveryQueue.php` | `releaseConnection()` agora é chamado somente pelo daemon ao finalizar o lote, antes da espera/saída. |
| `app/Http/Controllers/WhatsAppInboxController.php::watch` | Consulta autenticação/empresa/bot e desconecta antes da espera remota terminal. Com sessão em arquivo, não há SQL depois dessa desconexão. Se trocar sessão para banco, a gravação final da sessão pode reabrir a conexão; reavalie esse caminho. |
| `app/Console/Commands/DatabaseDoctor.php` | Diagnóstico explicitamente solicitado via CLI, com fechamento final. Não foi executado nesta revisão. |
| `scripts/hostinger.php` | A verificação/instalação pode chamar `getPdo()`; não é executada em cada página. Não foi executada contra o banco real. |
| `tests/load/mysql-worker.php`, `mysql-inspect.php`, `mysql-recovery.php` | Ferramentas que abrem conexão real se executadas. Não foram executadas. |
| `tests/Feature/DatabasePoolTest.php`, `DatabaseOperationsTest.php`, `CommerceTest.php`, `ConnectionReuseTest.php` | Inspeção de conexões lazy, mocks e/ou SQLite isolado nos testes. |

**Causa corrigida:** `MailQueue::send`, `WhatsApp::send` e `CatalogPix::generate` chamavam `DeliveryQueue::releaseConnection()` antes de SMTP/HTTP e voltavam a consultar/atualizar o banco depois. Fora de transação, com MySQL real, isso exigia nova conexão no mesmo envio/requisição. Um lote de N mensagens podia usar a conexão inicial mais aproximadamente N reconexões. Os testes usuais em SQLite em memória/transação não exercitavam esse comportamento. As três desconexões intermediárias foram retiradas. Cada execução/lote passa a reutilizar o PDO, salvo perda real da conexão; PDO persistente continua desabilitado.

Isso mantém uma conexão ocupada durante as chamadas externas desses envios; não mantém uma transação nova aberta durante a rede. O número de workers e as chamadas simultâneas ainda precisam caber no limite simultâneo da hospedagem. O fechamento final e a liberação do daemon foram preservados; fechar mais cedo, isoladamente, não reduz o contador horário.

## Páginas, rotas e trabalho que consomem SQL

O mapa de métodos e URLs permanece em `routes/poseitech.php`; a entrada é `public/index.php` → `bootstrap/app.php` → `routes/web.php`. Assets físicos existentes são servidos pelo Apache sem inicializar PHP. Os `.htaccess` não abrem conexões.

| Grupo de páginas/rotas | Acesso ao banco |
| --- | --- |
| `/`, login/cadastro, recuperação/redefinição de senha, logout e `/password/change` | Autenticação pode resolver usuário; POSTs consultam/gravam usuários/tokens. GET de login de visitante sem sessão autenticada não exige necessariamente SQL com sessão em arquivo. |
| `/admin*`, inclusive suporte e configurações da plataforma | Usuários, empresas, planos, suporte; consultas globais autorizadas da plataforma. |
| `/dashboard`, `/search`, `/records/*`, `/customers/*`, `/customers/merge*`, `/opportunities` | Dados comerciais, pesquisa, clientes e operações de união; middleware carrega usuário, empresa e plano. |
| `/sales*`, `/receipt/*`, `/orders*`, catálogo administrativo e cupons | Vendas, itens, estoque, pagamentos, recibos e pedidos; transações e isolamento existentes. `/coupons/preview` consulta o cupom sob demanda, com debounce de 350 ms no navegador. |
| `/cash*`, `/cash-flow*`, `/finance*`, `/accounts*`, `/credit*`, `/stock*`, `/reports*`, `/alerts*`, `/campaigns*`, `/settings*`, `/staff*` | Caixa, contas, créditos, estoque, relatórios, alertas, campanhas e configurações, incluindo envios manuais. |
| `/portal/access/*`, `/portal`, `/portal/logout`, `/catalog/{slug}*` | Empresa, cliente/visitante autorizado, produtos e histórico/pedidos; cadastro, pedido e Pix gravam quando solicitados. |
| `/media/{companyId}/{productId}`, `/logo/{companyId}`, `/catalog-media/{companyId}/{kind}` | Mesmo sendo imagens, passam por PHP e consultam empresa/produto/autorização antes de servir o arquivo. Cache HTTP já existente pode reduzir pedidos ao servidor; não foi ampliado. |
| `/whatsapp*`, incluindo `/whatsapp/atendimento/*` | Bots, conversas, mensagens, anexos, leitura, filtros, equipe e respostas rápidas. Cada pedido autenticado pode abrir conexão mesmo quando só aguarda o Baileys. |
| `/support*`, `/admin/tickets*` | Tickets, mensagens e anexos após autorização por empresa/perfil. |
| `/settings/mercadopago`, `/accounts/*/mercadopago`, `/payments/mercadopago/*`, `/pay/*`, `/integrations/mercadopago/*/webhook` | Links, confirmação de pagamentos e webhooks; integridade/idempotência existentes preservadas. |
| `/sitemap.xml`, `/sitemaps/catalogs-*.xml` | Podem consultar empresas de catálogos públicos; cache de sitemap já existente. `/robots.txt`, `/up` e páginas legais não exigem dados comerciais por si só; o middleware/sessão pode resolver um usuário autenticado em páginas web. |
| Jobs `SendWhatsAppReceipt`, `SendMercadoPagoLink`; comandos de administração e migrations/seeders | Usam o mesmo gerenciador no processo de execução. Não foram rodados em produção. |

Reduzir dez consultas para uma dentro de uma requisição que já usa o mesmo PDO melhora custo SQL, mas **não** economiza nove conexões. Para a quota horária, os ganhos principais são retirar reconexões e reduzir a quantidade de requisições/processos que chegam ao banco.

## Atualização automática e correções

| Origem | Antes → depois / situação |
| --- | --- |
| Processamento manual de e-mail (`public/assets/app.js`, `/settings/mail`) | Uma requisição por mensagem e outra para descobrir fila vazia → até cinco tentativas por requisição, iniciadas dentro de uma janela de dez segundos. Uma chamada SMTP já iniciada segue seu timeout. Resposta informa quantidade e se há mais; evita a última requisição vazia. O lote mantém o filtro de empresa e o tratamento de falhas/cancelamentos. |
| Pix (`catalog-payment.js`, status de catálogo ou `/pay/*/status`) | Consulta a cada oito segundos indefinidamente enquanto pendente → primeira consulta em 15 s e intervalos de 30/45/60 s quando o status não muda. Página oculta não consulta; status final encerra. Há timeout, cancelamento ao sair, pausa em perda de acesso e respeito a `Retry-After`. Após a rampa, cerca de 60 consultas/hora por aba pendente, em vez de 450, sem contar outros acessos. Confirmação no servidor continua por webhook/agendador; a tela pode demorar até um minuto para refletir mudança. |
| Atendimento (`whatsapp-inbox.js`) | Fallback sem modo ao vivo passa de 30 para 60 s. O modo ao vivo já usava reconciliação de 60 s e foi preservado; escoamento de lotes pendentes mantém intervalo de 3 s. Falhas têm recuo progressivo; `Retry-After` pausa tanto `/state` quanto `/watch`, inclusive ao voltar à aba. Sessão expirada/acesso recusado encerra atualizações automáticas. |
| Suporte (`support-chat.js`) | Mantém atualização progressiva de 15 até 60 s quando não há mensagens e somente em aba visível com foco. Agora respeita `Retry-After`, encerra ao perder acesso e cancela ao sair. |
| Conexão/QR WhatsApp (`whatsapp.js`) | Já consulta a cada 12 s, por no máximo dois minutos, enquanto conecta e a aba está visível; conectado ou com erro encerra. Mantido. |
| Cupom e prévias de templates | Cupom consulta após interação com debounce; prévia do designer é local. Não foi criado cache de resultado de cupom que pudesse ficar incorreto após mudar cliente/itens. |

Nenhum cache de clientes, usuários, permissões, saldos ou pagamentos foi acrescentado. Dados continuam sob `company_id`/autorização existentes; respostas privadas continuam com seus controles de cache. Sessão/cache locais já estavam em `file`; `QUEUE_CONNECTION=database` permanece. Não foram habilitados PDO persistente, proxy, pool externo, outro banco ou banco por empresa.

## Agendador e orçamento horário

`routes/commands.php` já agenda `poseitech:deliver --once` a cada minuto (quando `DELIVERY_DAEMON=false`), `poseitech:mercadopago` a cada cinco minutos e `poseitech:automate` diariamente às 06:00 no timezone configurado da aplicação. Há locks contra sobreposição e filtros de pausa de capacidade. As frequências foram preservadas para manter entregas e reconciliação de pagamentos. Os comandos manuais `poseitech:mail`, `poseitech:whatsapp`, `poseitech:admin` e o daemon também consultam o banco quando executados; confira se o painel não os agenda em duplicidade com `schedule:run`.

Em acesso direto sem persistência, só os dois cron jobs frequentes representam aproximadamente **72 conexões/hora**, mesmo com filas vazias, além da automação diária. O `schedule:run` com cache em arquivo não conecta por si só se nenhuma tarefa com SQL for executada. Isso deixa cerca de 428 para HTTP e outras ferramentas, se não houver mais processos usando a mesma conta. São estimativas do código, não medições do provedor.

Uma aba de atendimento ao vivo ainda abre conexões para `/watch` (a espera remota é de até 28 s) e para `/state` (até uma por minuto sem eventos); havendo mensagens, filtros ou ações, haverá outras requisições. A duração efetiva da espera do Baileys altera essa taxa. Várias abas/usuários podem ultrapassar 500/h mesmo após as correções. Não foi feito cache de autorização para contornar esse acesso.

O daemon não está ativo no `.env` examinado. Se for ativado, ele libera a conexão a cada lote e o padrão `--sleep=3` pode gerar aproximadamente 1.200 ciclos/conexões por hora por processo em ociosidade. Não o habilite com esse ritmo neste plano. Dimensione intervalo e workers com o provedor; não execute daemon e cron de entrega em paralelo. Mudar apenas variáveis `DB_POOL_*` não cria um pool real.

## Publicação e validação pendente

Arquivos alterados nesta revisão (as demais alterações locais pré-existentes não fazem parte desta lista):

- `app/Services/MailQueue.php`, `app/Services/WhatsApp.php`, `app/Services/CatalogPix.php`: retirada das desconexões intermediárias.
- `app/Services/DeliveryQueue.php`: comentário sobre o único uso remanescente da liberação, ao terminar lote do daemon.
- `app/Http/Controllers/SettingsController.php`, `public/assets/app.js`: lote manual de e-mail e contagem/encerramento no navegador.
- `public/assets/catalog-payment.js`, `public/assets/whatsapp-inbox.js`, `public/assets/support-chat.js`: redução/controle das atualizações automáticas.
- `deploy/hostinger.env.example`, `scripts/hostinger.php`: host local confirmado nos modelos de configuração, sem sobrescrever `.env` existente.
- `deploy/CONEXOES-HOSTINGER.md`, `deploy/MYSQL.md`: inventário, limitações e orientações desta revisão.
- `tests/Feature/ConnectionReuseTest.php`, `tests/Feature/CommerceTest.php`: regressão de reutilização do PDO fora de transação e lote isolado por empresa.
- `scripts/whatsapp-inbox.test.cjs`, `tests/support-chat.cjs`, `tests/payment-poll.cjs`, `scripts/check.cjs`: verificações das pausas/intervalos e inclusão do teste de Pix no comando de desenvolvimento.

Validação executada:

- `node scripts/check.cjs`: sintaxe PHP e JavaScript aprovada.
- Testes de frontend, suporte, atendimento e Pix com navegador/rede simulados: aprovados, incluindo dez casos via `node --test` para atendimento/Pix.
- Seleção de 119 casos PHP sobre comércio, catálogo, atendimento, filas, capacidade e conexões: 118 passaram na rodada principal; o caso novo de lote teve sua fixture de empresa corrigida para incluir o campo obrigatório `modules` e passou na repetição isolada. Nenhuma falha ficou pendente nessa seleção.
- A execução PHP forçou `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, ausência de `DB_URL`, cache/sessão em array e caminho de configuração sem cache externo. SMTP e HTTP externos dos cenários testados foram simulados. O teste novo verifica explicitamente SQLite em memória antes de inicializar suas tabelas.

Publique os arquivos alterados preservando `.env`, `APP_KEY`, uploads e dados. **Esta correção não exige migration, seeder ou alteração de esquema.** No servidor publicado, confira `DB_HOST=localhost` conforme confirmação do responsável, ausência de `DB_URL`/socket conflitante e o cache de configuração gerado no próprio servidor. Se precisar atualizar a configuração, `php artisan config:cache` recompõe o cache; não use comandos de reinstalação ou limpeza de dados. Reinicie workers existentes se houver.

A Hostinger ainda precisa confirmar a quota efetiva para a conexão local, limite simultâneo e eventuais configurações PHP que afetem conexões. Acompanhe o contador horário com acessos normais após publicar, incluindo cron, imagens dinâmicas, abas e outras aplicações que compartilhem a conta. Não execute os scripts de carga MySQL para comprovar esta correção em produção.

**Não há evidência de eliminação do limite.** Se o uso necessário continuar exigindo mais de 500 novas conexões por hora, ajuste o uso/quantidade de abas e tarefas ou consulte a Hostinger sobre o limite do plano, mantendo PHP/MySQL na hospedagem. A configuração de pool descrita no guia anterior não foi ativada por esta entrega.
