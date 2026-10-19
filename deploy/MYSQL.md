# MySQL em produção e pool de conexões

## Arquitetura e auditoria

O CRM usa PHP >=8.2, Laravel 12, Query Builder/Eloquent e PDO MySQL/MariaDB. HTTP entra em `public/index.php`, via Apache/PHP ou FPM. `artisan serve` é desenvolvimento. Node/Baileys não consulta MySQL. Não há Octane/Swoole nem runtime concorrente PHP no projeto. O Laravel mantém uma conexão lazy por nome na execução, compartilhada entre serviços; não há conexão ou pool por empresa. PDO não persistente fecha ao terminar a execução. Habilitar persistência PDO não cria um pool global limitado entre workers.

O repositório contém guias para hospedagem compartilhada e Supervisor com quatro processos de entrega, mas não fixa a topologia real, `pm.max_children`, workers Apache, escalabilidade, versão do MySQL/MariaDB, quotas por usuário, conexões por hora ou capacidade do provedor. O ambiente local usa MySQL; nenhuma credencial foi copiada ou modificada. Não é possível deduzir capacidade a partir de 500 empresas ou 1.500 usuários cadastrados.

Inventário do acesso SQL (inclui consultas globais intencionais):

| Local | Acesso e proteção |
| --- | --- |
| `app/Services/Tenant.php` e serviços comerciais | Query Builder com `company_id`, lista de tabelas permitidas, inserts forçam empresa, updates removem tentativa de trocar empresa. |
| `Commerce`, `CashFlow`, `CustomerWallet`, `CustomerMerge`, `CatalogPix`, `MercadoPago`, `CouponRewards`, `SaleNotifications` e controllers de negócios | Transações Laravel, locks e/ou idempotência existentes; nenhum retry novo. Venda serializa pela linha da empresa. |
| Controllers de recursos, relatórios e `Analytics` | Escopo Tenant; joins de relatórios/cupons/pedidos reforçados com igualdade de `company_id`. |
| Catálogo, portal e pagamento público | Empresa resolvida por slug, sessão validada, hash/token ou referência pública; consultas subsequentes respeitam empresa. Portal verifica assinatura/hash; webhook verifica assinatura antes de processar. |
| Admin, autenticação, models Company/Plan/User | Tabelas globais, acesso de plataforma autorizado ou usuário autenticado; sessões removidas por usuário. |
| `SupportTicketController` | Ticket filtrado por empresa antes de ler mensagens/anexos pelo ticket; suporte global tem autorização própria. |
| `DeliveryQueue`, jobs, `MailQueue`, WhatsApp e `routes/commands.php` | Seleção global deliberada de trabalho; empresa selecionada antes do processamento. Conexões já liberadas antes de chamadas externas e espera do daemon, desde que fora de transação. |
| `database/`, `scripts/hostinger.php`, bootstrap, cache/sessão/fila Laravel | Instalação/migrations e infraestrutura usam o gerenciador Laravel; não criar conexões manuais adicionais. |

A busca no código de aplicação não encontrou `mysqli`, instâncias manuais de PDO nem pools paralelos. Reutilização é feita pelo DatabaseManager, não por chamadas manuais de abrir/fechar a cada consulta. Testes existentes cobrem rollback comercial, idempotência, acesso cruzado, catálogo, portal e filas. Isso não substitui auditoria contínua de novas consultas.

## Pool compartilhado por instância

Para limitar e reutilizar conexões físicas entre processos PHP, esta entrega prepara **ProxySQL local por instância do backend**, compartilhado por HTTP, cron e workers locais. Não instala nem inicia infraestrutura automaticamente. Mantenha PDO não persistente. O proxy mantém o pool físico e fixa a conexão durante uma transação (`transaction_persistent=1`), sem roteamento de leituras ou réplicas. Isso preserva as transações e `lastInsertId` usados no código. Consultas e conexões não têm retries no proxy; o adaptador Laravel também não repete consultas que perderam confirmação. Reconexão inicial antes de executar SQL ainda segue o conector Laravel.

Valores iniciais para homologação, não uma previsão de capacidade:

| Variável | Padrão | Efeito |
| --- | --- | --- |
| `DB_CONNECT_TIMEOUT` | 3 s | Estabelecer conexão PDO com proxy ou MySQL direto. Não é timeout de consulta. |
| `DB_POOL_SIZE` | 10 | Máximo físico por proxy/backend/hostgroup. |
| `DB_POOL_CLIENTS` | 32 | Máximo de sessões PHP aceitas por proxy e usuário. |
| `DB_POOL_WAIT_TIMEOUT_MS` | 3000 ms | Prazo máximo de obtenção de backend; erro 9001 gera 503 e cooldown. |
| `DB_QUERY_TIMEOUT_MS` | 10000 ms | Prazo de consulta no proxy, inclusive escritas; timeout pode ter resultado incerto. |
| `DB_POOL_INSTANCES` | 1 | Total máximo simultâneo de proxies ativos, incluindo autoscaling e sobreposição de deploy. |
| `DB_CONNECTION_RESERVE` | 5 | Soma de conexões fora dos pools: administração, migrations, outros apps e monitores, conforme ambiente. |
| `DB_CONNECTION_BUDGET` | 0 | Orçamento confirmado para este CRM, limitado pelo menor entre quota de usuário e capacidade disponível do servidor. Zero impede gerar configuração. |
| `DB_UPSTREAM_HOST/PORT` | vazio / 3306 | Destino real atrás do proxy; deve ser preenchido explicitamente. |
| `DB_CAPACITY_COOLDOWN_SECONDS` | 300 s | Circuito local já existente para quota/esgotamento; configurável. |

Limite físico previsto: `DB_POOL_INSTANCES × DB_POOL_SIZE + DB_CONNECTION_RESERVE`. Dois proxies com pool 10 e reserva 5 exigem orçamento 25. **Isso não altera `max_connections` do MySQL.** A reserva deve incluir tudo que bypassa o proxy; se ativar monitoramento ou adicionar backends/hostgroups, recalcule. Limite os workers por instância: `workers HTTP + workers de fila/entrega + comandos cron simultâneos + deploy <= DB_POOL_CLIENTS`. Sem proxy, a aproximação é `instâncias × (workers HTTP + workers background/cron) × conexões por processo + reserva`; tamanho de pool não limita PDO direto. O valor de `DB_POOL_INSTANCES` é declarativo: o operador deve impedir escalar além dele.

## Preparação e ativação

1. Confirme com a hospedagem se há proxy gerenciado ou permissão para instalar ProxySQL. Em hospedagem compartilhada sem esse suporte, não existe pool externo ativo: mantenha acesso direto, limite workers e obtenha solução do provedor ou migre a infraestrutura. As variáveis de pool sozinhas não mudam o banco.
2. Em homologação isolada, instale uma versão suportada de ProxySQL e valide a compatibilidade com o servidor e plugin de autenticação reais. O gerador usa formato libconfig e um único backend. Use usuário dedicado ao CRM, sem privilégios de administração. Não use root.
3. Preencha `DB_UPSTREAM_HOST`, credenciais `DB_*`, orçamento, limites e número máximo de proxies. Não use `DB_URL` para gerar, pois pode substituir silenciosamente host e credenciais. Execute `php artisan config:clear` e `php artisan poseitech:pool-config`. O comando não conecta no banco nem imprime segredos. Gera `storage/app/private/database-pool/proxysql.cnf`, ignorado pelo Git e sem sobrescrever arquivo existente. Configure ACL restrita no Windows; em Unix o arquivo nasce com permissões 0600.
4. Revise e instale o arquivo como configuração privada do serviço ProxySQL. Ajuste `datadir` para diretório privado gravável. O frontend 6033 e admin 6032 ficam em loopback; não publique essas portas. O gerador cria senha administrativa aleatória no arquivo privado; guarde-a em armazenamento de segredos e confirme que as credenciais padrão não estão ativas. Monitor e query digests/event log estão desativados para não incluir conexões ocultas nem capturar SQL com dados. Logs operacionais do proxy/servidor também precisam de acesso restrito e revisão de conteúdo. Não habilite general log/slow log com parâmetros sensíveis em produção.
5. **TLS é configuração específica do destino.** Este arquivo inicial não configura TLS upstream. Use-o apenas em rede local privada confiável até configurar CA, verificação de identidade e TLS entre proxy e servidor conforme a versão escolhida e o provedor. `MYSQL_ATTR_SSL_CA` controla PDO→destino, não o trecho proxy→MySQL. Nunca suponha que TLS em um trecho protege o outro. Não publique com upstream pela Internet sem validar ambos.
6. Inicie o serviço supervisionado com essa configuração. O arquivo é carregado na inicialização inicial; com `proxysql.db` existente, variáveis persistidas podem prevalecer. Atualizações exigem procedimento Admin `LOAD ... TO RUNTIME` / `SAVE ... TO DISK` revisado e verificado; não apague o banco interno de um serviço ativo para forçar reload. Confira em runtime `mysql_servers.max_connections`, timeouts e retries zero. Não edite o arquivo esperando atualização automática.
7. Depois de testar o proxy, altere o CRM para `DB_HOST=127.0.0.1`, `DB_PORT=6033`, `DB_SOCKET=` e sem `DB_URL`, mantendo schema e credenciais. Execute `php artisan config:cache` e reinicie workers. Nenhuma credencial real faz parte dos exemplos. Se Redis for usado em múltiplas instâncias, compartilhe sessões/cache/locks como descrito em `ENVIOS-E-ESCALA.md`.
8. Execute migrations em uma conexão administrativa **direta** separada, dentro da reserva, com configuração própria. O timeout de 10 segundos é para o tráfego normal; pode interromper DDL e relatórios longos. Não altere timeout global sem medir.

## Erros, transações e desligamento

PDO MySQL não oferece um timeout portátil para qualquer consulta via `ATTR_TIMEOUT`; a limitação completa de consulta/espera desta solução está no proxy. Em conexão direta apenas o estabelecimento fica limitado pela aplicação: solicite os limites de statement/lock compatíveis com a versão do servidor ao provedor. Não confunda os dois modos.

Quota MySQL 1040/1203/1226 e esgotamento 9001 retornam 503 com `Retry-After` pelo circuito existente, incluindo falhas PDO iniciais. O cooldown usa arquivo **local**, não um circuito distribuído; cada instância precisa atingir sua própria proteção. Demais erros SQL seguem o tratamento de erro do Laravel e são registrados apenas com SQLSTATE/código, sem SQL, bindings, senha ou mensagem do driver. Timeout de consulta e perda de confirmação **não autorizam reenviar uma venda/cobrança automaticamente**. Confira resultado por chave idempotente/referência antes de qualquer ação manual. Falha no commit pode significar commit realizado sem confirmação.

As transações existentes continuam com uma tentativa por padrão. Um erro de statement deve propagar para o Laravel desfazer a transação inteira; nunca capture um timeout dentro da transação e continue gravando. Testes de venda/estoque/caixa verificam a consistência. O encerramento normal HTTP/Artisan desfaz qualquer transação esquecida e desconecta conexões MySQL/MariaDB já criadas. O daemon mantém a liberação entre lotes e antes de I/O externo. Em morte abrupta, o fechamento do socket provoca abandono da transação pelo servidor; não garante confirmação de commits anteriores.

No deploy, retire a instância do balanceador, pare a entrada de jobs, deixe requisições/transações e lotes terminarem e encerre os processos PHP. Só depois pare o proxy local pelo gerenciador de serviços/SIGTERM; ele é dono do pool e fecha os sockets backend. Configure prazo de parada superior ao maior request/lote (o guia de entregas usa 180 s). Não desligue o proxy por requisição nem use SIGKILL como parada normal. Atualizações sobrepostas contam no orçamento. Esta preparação não acrescenta suporte a Octane: se adotar um runtime PHP concorrente, revise lifecycle e isolamento antes.

## Validação de capacidade antes de publicar

Use banco e dados sintéticos exclusivos de homologação, MySQL/MariaDB da mesma versão, limites reais, pool e topologia iguais aos planejados. Não aponte a suíte SQLite nem um gerador de carga ao banco de produção.

1. Rode `php artisan test`, `php vendor/bin/pint --test` e `npm test`. Os testes locais verificam a reutilização lazy, liberação mesmo com erro, recusa de orçamento insuficiente, configuração do proxy, ausência de retry de escrita e resposta segura ao esgotamento. SQLite/mocks não provam multiplexação real.
2. No ambiente com proxy, exercite login, catálogo, pesquisa, relatórios, venda, recebimento e estorno, usando empresas distintas e chaves idempotentes. Force erro após movimentar estoque e confirme rollback de venda, pagamentos, estoque e caixa; execute concorrência na mesma empresa e entre empresas. Faça leituras e inserts alternados para verificar ausência de estado de sessão residual e correlação correta de IDs.
3. Em carga separada, ocupe todas as conexões com transações controladas e verifique espera limitada e 503/9001. Derrube uma sessão, interrompa o proxy e reinicie o backend; confira ausência de repetição automática e resultados idempotentes. Teste timeout em leitura, escrita e commit, e a recuperação de conexões após rollback e encerramento de workers. Simule deploy sobreposto.
4. Suba concorrência em degraus conforme tráfego esperado, com picos de catálogo, gravações e cron sobrepostos; mantenha carga por tempo suficiente para detectar crescimento de conexões. Meça throughput, p50/p95/p99, 5xx, espera/timeouts, transações longas, locks/deadlocks, CPU, RAM, I/O, `Threads_connected`, `Threads_running`, conexões criadas e tamanho/idade das filas. Em `stats_mysql_connection_pool`, acompanhe ConnUsed/ConnFree/ConnERR/MaxConnUsed e confirme que a soma física respeita o orçamento. Não exporte SQL ou dados de clientes para telemetria.
5. Defina com o negócio as metas de latência e erro antes de aceitar o teste. Ajuste consultas/índices e concorrência antes de ampliar pool. Um pool maior pode piorar CPU e locks. Só aumente limites depois de confirmar headroom e quotas do provedor, inclusive limites por hora.

Faltam medir a curva de chegada dos acessos, consultas por rota, tempo de ocupação de conexão, pico de workers e capacidade real do banco. Não foi executado teste real do ProxySQL ou carga nesta entrega. Pool limita pressão e reutiliza sockets; disponibilidade ainda depende de rede, proxy, backups testados, replicação/failover e SLA do MySQL. Um proxy local único também é um ponto de falha da instância.

Referências oficiais: [PDO e fechamento de conexões](https://www.php.net/manual/en/pdo.connections.php), [persistência por worker PHP](https://www.php.net/manual/en/features.persistent-connections.php), [variáveis e timeouts ProxySQL](https://proxysql.com/documentation/global-variables/mysql-variables/), [carregamento da configuração ProxySQL](https://proxysql.com/documentation/configuration-file/).

Validação desta entrega: `npm test` passou (sintaxe PHP/JavaScript, testes JavaScript e 154 testes PHP, 1.510 assertions). Pint passou nos arquivos desta alteração. O Pint global aponta formatação preexistente em `AdminController.php` e `AuthController.php`, que não foram alterados. Não há script de build de frontend nem verificador de tipos configurado no projeto. Testes MySQL/ProxySQL e de carga continuam pendentes no ambiente dedicado descrito acima.
