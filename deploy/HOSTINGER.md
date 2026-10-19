# Implantação em hospedagem compartilhada

Consulte também [MySQL e pool de conexões](MYSQL.md). Confirme se seu plano oferece proxy gerenciado ou permite instalar ProxySQL; não altere a porta do banco nem presuma suporte a processos próprios em hospedagem compartilhada.

Este guia descreve o uso de `scripts/hostinger.php`, incluído no projeto para instalações de produção com MySQL/MariaDB e HTTPS. O script contém autodetecção de caminhos PHP da Hostinger; não é um instalador universal para SQLite ou desenvolvimento local. Para Windows, Linux, macOS, servidores próprios e contêineres, use o procedimento geral do [README](../README.md).

Todos os domínios, e-mails e caminhos dos exemplos são ilustrativos. Substitua-os pelos valores do ambiente. Execute os comandos na pasta que contém `artisan`. `php` deve apontar para um executável compatível com `composer.lock`; `composer` deve utilizar esse mesmo PHP.

## PHP do site e do terminal

Selecione PHP 8.2 ou superior no painel e confira a versão do terminal:

```sh
php -v
php --ini
composer --version
```

A seleção no painel pode não alterar o PHP do SSH. Se houver diferença, use o caminho do executável fornecido pela hospedagem em todos os comandos de Composer, Artisan, instalador e agendamento. Com Composer em PHAR, use esse PHP para executar `composer.phar`.

Quando iniciado com PHP anterior a 8.2, o instalador tenta localizar executáveis compatíveis nos caminhos previstos pelo script e reiniciar, se a execução de processos for permitida. Isso não altera o PHP global do terminal. Não reduza a versão exigida pelo projeto nem use `--ignore-platform-reqs` para contornar incompatibilidades.

## Organização das pastas

Prefira manter a aplicação fora da raiz pública e apontar o domínio para o diretório `public/` do projeto.

Quando a hospedagem exigir uma pasta pública separada, `public/index.php` também reconhece esta estrutura específica:

```text
pasta-do-site/
├── poseitech/          # aplicação: artisan, app, bootstrap, vendor, .env etc.
└── public_html/        # conteúdo de public/, incluindo index.php e .htaccess
```

Nessa alternativa, `poseitech` é o nome esperado pelo código, não um nome arbitrário. As pastas ficam no mesmo nível. Publique também arquivos ocultos, como `.htaccess`. Use um domínio dedicado e preserve arquivos de outros sites.

## Instalação nova

1. Configure PHP e extensões conforme o README, incluindo `pdo_mysql`. Garanta escrita em `storage/` e `bootstrap/cache/` pelo usuário da aplicação.
2. Publique o projeto completo na estrutura escolhida. Instale as dependências na raiz da aplicação:

```sh
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
```

3. Crie uma base MySQL/MariaDB e um usuário autorizado. Inicialize o modelo de ambiente:

```sh
php scripts/hostinger.php --init-env
```

Se não existir `.env`, o script cria um modelo e encerra sem alterar o banco. Um arquivo existente é preservado. Habilite a exibição de arquivos ocultos no gerenciador para editá-lo.

4. Preencha `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` com o domínio HTTPS real, `SESSION_SECURE_COOKIE=true` e as credenciais reais do banco. O arquivo [hostinger.env.example](hostinger.env.example) é um modelo, não uma configuração pronta. Ajuste SMTP e demais serviços para esse ambiente.
5. Confira que o banco selecionado é o correto e execute:

```sh
php scripts/hostinger.php --install
php artisan poseitech:admin admin@example.com
```

Substitua o e-mail de exemplo. O instalador verifica requisitos, prepara diretórios necessários, remove caches gerados, gera a chave apenas se ausente, aplica migrations, inicializa planos e recompila caches de rotas e views. Não é necessário Node.js ou Vite para servir a aplicação.

6. Abra o domínio, confira a página de login e cadastre a empresa em `/register`. Configure a execução de `artisan schedule:run` a cada minuto com os caminhos reais do PHP e da aplicação, conforme o README. Teste banco, SMTP e tarefas agendadas no ambiente de destino.

Sem SSH ou terminal, a instalação e os comandos administrativos precisam ser executados pelo responsável pela hospedagem ou por um processo de implantação compatível. Não há instalador web público. Se enviar `vendor/` preparado em outro ambiente, ele deve atender ao PHP e às extensões do destino; não envie caches locais.

## Atualizar uma instalação existente

Faça backup do banco, uploads e configurações, incluindo a `APP_KEY`. Publique uma versão completa do projeto e preserve os arquivos próprios da instalação. Não siga as listas de arquivos das notas históricas como um pacote completo de atualização.

Execute na raiz da aplicação:

```sh
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
php scripts/hostinger.php --install
php artisan migrate:status
```

O instalador executa migrations e seeders: revise as mudanças da versão e o banco configurado antes de usá-lo. Não use `migrate:fresh` em uma base com dados. Preserve a chave original, inclusive ao transferir a aplicação entre servidores. Para implantação sem esse script, siga os comandos gerais do README.

## Chave da aplicação ausente

Se os logs mostrarem `MissingAppKeyException`, confira o `.env` na pasta do `artisan`. Em uma instalação que já possui dados criptografados, restaure a `APP_KEY` original antes de prosseguir.

```sh
php scripts/hostinger.php --fix-key
```

O comando mantém uma chave existente, gera uma somente quando ausente e remove caches gerados. Não executa migrations. Se o `.env` não existir, ele orienta a restaurá-lo ou inicializá-lo; não cria um ambiente novo automaticamente com essa opção.

Sem o script, execute `php artisan config:clear`, verifique a chave e use `php artisan key:generate --force` somente em uma instalação nova que ainda não tenha chave. Recompile o cache de configuração após concluir os ajustes.

## Diagnóstico de erro 500

Execute `php scripts/hostinger.php` para verificar a instalação de produção, incluindo conexão e tabelas. Consulte `storage/logs/laravel.log`, arquivos diários se esse canal estiver configurado e os logs de PHP/servidor da hospedagem.

- `vendor/autoload.php` ausente: instale as dependências com Composer na pasta da aplicação.
- PHP incompatível: alinhe o executável do terminal ao utilizado pelo site.
- Tabelas ou colunas ausentes: publique todas as migrations da versão e confira `php artisan migrate:status`.
- Caminhos de outro ambiente: limpe caches de configuração, rotas e views e gere-os no destino.
- Falha de escrita: ajuste proprietário e permissões de `storage/` e `bootstrap/cache/`, sem permissões 777.

Mantenha `APP_DEBUG=false` em produção. Não exponha `.env`, backups ou banco na pasta pública; oculte credenciais e dados de clientes ao compartilhar logs.

## Notas históricas de funcionalidades

As seções abaixo registram mudanças de versões anteriores. As instruções de implantação e atualização acima são as vigentes; referências a arquivos individuais ou à ausência de migrations valem somente para a mudança descrita. Publique sempre a versão completa e aplique todas as migrations pendentes. Nas notas, `php` representa o executável compatível escolhido para o ambiente.

## Histórico: fiado por cliente

### Comprovantes automáticos e salvamento conjunto de categorias

Envie a migração `2026_09_28_000004_automatic_sale_receipts.php`, os templates `resources/views/emails/receipt.blade.php` e `receipt-text.blade.php` e os demais arquivos alterados. Execute `migrate --force` com o PHP compatível do ambiente e limpe os caches.

Cada venda concluída com cliente que tenha e-mail válido cria um comprovante na fila, mesmo sem consentimento para mensagens promocionais: é uma comunicação sobre a própria compra. A venda e o registro da fila são gravados juntos, e repetir a mesma venda não duplica o e-mail. Vendas sem cliente ou sem e-mail continuam funcionando, sem envio. Falhas de SMTP não desfazem a venda; use a fila para tentar novamente.

O envio automático é realizado pelo cron existente (`schedule:run` a cada minuto, que executa `poseitech:mail`). Configure e teste o SMTP de cada empresa. Sem cron, as mensagens ficam pendentes até processamento manual. O HTML contém itens, valores, pagamentos, fiado e links para o comprovante e, quando disponível, o portal. Também existe uma versão em texto simples.

O portal permanece limitado ao mês selecionado, mesmo que uma URL antiga contenha `scope=all`. As duas listas de categorias são salvas juntas pelo botão **Salvar todas as categorias**; um erro em qualquer lista impede alterações parciais.

### Categorias por direção, indicadores do fiado e comprovantes

Esta atualização não exige migração. Envie todos os arquivos alterados e limpe os caches de views/configuração. As listas de categorias agora são configuradas separadamente para entradas e saídas. Listas personalizadas da versão anterior são preservadas inicialmente nas duas direções; ajuste cada lista em **Configurar categorias da empresa**. Categorias históricas continuam nos filtros mesmo depois de removidas das opções de cadastro.

Os cartões por forma de pagamento mostram também a diferença entre recebido e gasto. Nesta versão histórica, o portal permitia filtrar por todo o histórico; versões posteriores restringiram a listagem ao mês selecionado, conforme descrito acima. Os indicadores mostram o fiado original das compras do mês, o restante dessas compras e o total de fiado em aberto; a seleção da lista não altera esses indicadores. Comprovantes identificam o valor pago com saldo da conta e sua devolução quando a venda é cancelada.

### Datas retroativas, categorias e portal permanente

Esta atualização não exige migração adicional. Envie os arquivos novos `app/Services/FlowCategories.php` e `app/Services/PortalLink.php`, além dos arquivos alterados, e limpe os caches de rotas, views e configuração.

O fluxo permite escolher hoje ou uma data anterior, no fuso da empresa. O lançamento entra no mês selecionado e conserva a data real de cadastro para auditoria. Categorias são configuradas por administradores em **Fluxo de caixa → Configurar categorias da empresa**. Categorias retiradas da lista deixam de aceitar novos lançamentos, mas permanecem no filtro do histórico. Os cartões por forma de pagamento e os saldos respeitam o mês e a categoria selecionados.

Os links do portal não expiram, aparecem no perfil e podem ser revogados. Links antigos continuam funcionando, inclusive quando a antiga data de validade já passou. O link exibido no perfil usa uma assinatura segura baseada na chave da aplicação e no acesso atual do cliente; preservar a `APP_KEY` mantém esses links válidos. Revogar invalida tanto o link exibido como o link antigo e encerra o acesso das sessões do portal. Gerar novamente depois de revogar cria um novo acesso.

O portal mostra uma tabela de compras por mês, com número, valor e comprovante. Somente contas ainda em aberto aparecem em **Valores pendentes**, incluindo dívidas de meses anteriores. Créditos, cashback, saldo e extrato não são exibidos no portal; os registros permanecem preservados no sistema.

### SMTP Gmail, extrato agrupado e caixa obrigatório

Envie também `public/assets/layout-fixes.css`, `app/Services/CompanySmtp.php` e `resources/views/components/flow-statement.blade.php`, junto com os demais arquivos alterados. Limpe as views e configurações após atualizar. Esta etapa não cria novas tabelas.

Gmail: use `smtp.gmail.com`, e-mail completo como usuário, senha de aplicativo e remetente autorizado pela conta. Porta 465 usa SSL/TLS e 587 usa STARTTLS; o aplicativo corrige a combinação nas portas padrão e remove os espaços da senha de aplicativo Google. Referência: https://support.google.com/a/answer/176600 . Em Configurações, salve e clique em **Testar conexão SMTP salva**, depois tente uma mensagem da fila. O teste confirma conexão/autenticação, mas não garante aceitação de um remetente ou destinatário específico. A fila agora mostra a categoria da falha, sem guardar a senha ou a conversa SMTP no log.

Toda nova venda exige caixa aberto, inclusive Pix, cartão, fiado e saldo do cliente. Caixa é uma dependência obrigatória de Vendas. O extrato e o CSV têm uma linha por dia e forma de pagamento no fuso da empresa; a diferença corresponde às entradas menos as saídas daquele grupo. Abra o modo para conferir separadamente as origens das entradas e saídas.

### Depósitos, fluxo de caixa, equipe e fila de e-mails

Envie a migração `2026_09_28_000003_wallet_flow_and_team.php` junto com os novos controllers, serviços, views, rotas e arquivos `public/assets`. Execute `migrate --force` e limpe os caches com o PHP compatível do ambiente conforme os comandos abaixo. A aplicação avisa quando o banco ainda não recebeu a atualização.

- Em Nova venda, selecione **Depósito na conta do cliente**. O depósito quita primeiro os fiados mais antigos e mantém o excedente como saldo para compras futuras. Depósitos não contam como vendas; o uso do saldo não duplica entradas no fluxo. O cancelamento da compra devolve a parte paga com saldo à conta do cliente.
- **Fluxo de caixa** mostra o mês, saldo anterior/final, entradas, saídas, estornos, caixas e formas de pagamento, com extrato e CSV. Registre retiradas, despesas, aportes e o saldo inicial diretamente nessa tela. A abertura diária de caixa não é receita e não entra novamente no fluxo. O saldo considera apenas movimentações registradas no sistema; retiradas diretas não alteram o caixa operacional.
- Excluir alguém da equipe revoga o acesso e preserva as operações históricas. Usuários excluídos não ocupam o limite da equipe. Não é permitido remover o próprio acesso ou o último administrador ativo.
- Em Configurações, a fila permite enviar uma mensagem, tentar todas as pendentes/falhas ou cancelar a fila. Para enviar todas, mantenha a página aberta. O cron `poseitech:mail` continua processando pendentes automaticamente. A limpeza preserva o histórico e não interrompe mensagens já em envio. Uma falha de rede após aceitação pelo SMTP pode deixar o resultado incerto; confira com o destinatário antes de repetir uma mensagem nessa situação.


Se Produtos ou Equipe exibirem `Unknown column 'deleted_at'` ou `Unknown column 'must_change_password'`, envie também o arquivo `database/migrations/2026_09_28_000002_password_and_product_deletion.php` antes de executar os comandos abaixo. Esses erros indicam banco sem a migração correspondente. A migração aceita uma execução anterior parcialmente concluída, sem recriar colunas existentes. Confirme com `php artisan migrate:status`, usando o mesmo executável PHP compatível. Não use `migrate:fresh` em produção.

Envie também `public/assets/app.js`, `public/assets/app.css` e as views atualizadas. O layout inclui uma versão nos endereços desses arquivos para que o navegador carregue a busca e a máscara novas.

As atualizações seguintes também incluem exclusão de produtos preservando o histórico e troca obrigatória de senha. Após aplicar as migrações, todas as contas existentes deverão escolher uma nova senha no próximo acesso. Novos usuários e usuários cuja senha seja redefinida pela administração também deverão alterá-la. A recuperação por e-mail já permite definir a senha pessoal. Somente o suporte da plataforma pode alterar módulos, entrando na empresa pelo acesso de suporte. Administradores e funcionários podem trocar a própria senha em Configurações.

Depois de enviar os arquivos atualizados, execute na pasta que contém `artisan`:

```bash
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

A migração mantém as vendas e os pagamentos existentes e prepara o saldo devedor por cliente. Clientes existentes recebem dia de vencimento 5; ajuste no cadastro quando necessário. Nos meses sem o dia escolhido (por exemplo, 31), o vencimento usa o último dia do mês. Preserve o `.env` e a `APP_KEY` atuais.


### Atualização: vendas, recompensas e catálogo (29/09)

Envie todos os arquivos alterados, incluindo `database/migrations/2026_09_29_000001_catalog_and_coupon_rewards.php`, `public/assets/catalog.css` e `public/assets/catalog.js`. Na pasta do `artisan`, execute:

```bash
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

- **Venda:** marque Item avulso para informar nome, preço e quantidade sem criar cadastro. O leitor deve operar como teclado e finalizar com Enter; mantenha o foco em Leitor de barras. O campo Código do produto deve conter o código lido e ser exclusivo entre produtos ativos. Leituras repetidas somam a quantidade por leitura. Venda sem estoque exige confirmação e gera auditoria; o estoque pode ficar negativo para reposição posterior.
- **Cupons:** compra mínima maior que zero é o subtotal necessário para conquistar o benefício, uma vez por cliente e cupom, em novas vendas. O resgate ocorre em uma próxima compra de qualquer valor; o desconto fica limitado ao subtotal. Prazo, cliente exclusivo e limite global de usos continuam valendo. Cupom com compra mínima zero continua sendo um código livre. O histórico mostra conquista, estado do e-mail e venda de uso. Excluir arquiva o cupom e preserva histórico. Cancelar a compra que o originou revoga o benefício; cancelar o resgate libera o uso novamente se o benefício continuar válido.
- **Envios:** cliente identificado e com e-mail válido recebe a recompensa pela fila SMTP existente. Mantenha o cron `poseitech:mail` ativo. Estar na fila não significa que o SMTP já entregou a mensagem.
- **Catálogo:** em Configurações, personalize cor, título e apresentação, copie o link e habilite pedidos online. Requer módulo Pedidos. O carrinho envia uma solicitação, sem cobrar ou reservar estoque. Na tela Pedidos, confira os dados e registre a venda; selecione/cadastre o cliente se necessário e confirme os preços atuais com ele. O caixa precisa estar aberto. Frete e pagamento são combinados no atendimento. Pedidos online duplicados não geram duas vendas.


### Cupons em tempo real e histórico do catálogo

Envie também a migração `2026_09_29_000002_coupon_products_and_catalog_history.php`, os novos controllers/partials e os assets atualizados. Execute `migrate --force`, `config:clear`, `route:clear` e `view:clear` com `php` na pasta do `artisan`.

- Cupons são validados automaticamente ao digitar e ao mudar cliente, itens ou desconto. A prévia não consome usos; o fechamento valida novamente. Produto grátis abate o preço-base de uma unidade do produto configurado, que deve estar na venda. Adicionais são cobrados. Valor e percentual continuam disponíveis. A listagem mostra validade, status e quantidade de usos, com filtros.
- Para alterar o endereço do catálogo, o suporte entra na empresa e acessa Configurações > Catálogo > Link do catálogo. O link antigo deixa de funcionar; o novo deve ser compartilhado. A permissão é validada no servidor e a alteração é auditada.
- O carrinho é preservado no armazenamento local por empresa, sem guardar nome, telefone ou endereço. Recarregar a página recalcula preços atuais e ajusta quantidades ao estoque. Após envio confirmado, o carrinho é limpo.
- Meus pedidos usa um cookie privado persistente por empresa. Mostra os pedidos feitos neste navegador após esta atualização, os estados registrado/cancelado e o andamento em Pedidos. Não recupera compras antigas sem esse identificador, nem compartilha histórico entre dispositivos ou após limpeza dos cookies. A página retorna `Cache-Control: private, no-store`; mantenha o cache de página/CDN desabilitado para `/catalog/*` para não compartilhar dados de visitantes.


### Layout do catálogo e de Nova Venda

Esta revisão reorganiza o catálogo em formato de cardápio com categorias, produtos compactos, sacola lateral e identificação na etapa de finalizar. Nova Venda separa cliente, itens e pagamento; campos opcionais ficam em seções expansíveis. Pedidos do catálogo mostra apenas solicitações pendentes sem venda registrada, preservando o histórico dos demais pedidos.

Envie as views, `app/Http/Controllers/BusinessController.php`, `public/assets/catalog.css`, `public/assets/catalog.js` e o novo `public/assets/sale.css`. Execute `php artisan view:clear`. Esta revisão de layout não acrescenta migrações; as migrações anteriores continuam necessárias caso ainda não tenham sido aplicadas.
