# Instalação na Hostinger

## PHP 8.3 no hPanel, mas PHP 8.1 no terminal

A versão do site e a versão do SSH são independentes. Envie o `scripts/hostinger.php` atualizado: quando iniciado em PHP antigo, ele procura o PHP 8.3, 8.4 ou 8.2 nos caminhos da Hostinger e reinicia automaticamente, se a hospedagem permitir iniciar processos.

Para usar diretamente o PHP 8.3, sem depender do comando `php` do terminal:

```sh
cd /home/u382808979/domains/crm.poseitech.com.br/public_html
/opt/alt/php83/usr/bin/php scripts/hostinger.php --php-info
/opt/alt/php83/usr/bin/php scripts/hostinger.php --install
```

Se o `.env` estiver ausente, `--install` cria um modelo de produção e para. Abra o arquivo **oculto** `.env` na pasta indicada, preencha `APP_URL=https://crm.poseitech.com.br` e as credenciais reais do MySQL do hPanel; repita o último comando. Modelos incompletos não executam migrations. Um `.env` existente nunca é sobrescrito.

Em uma instalação que já tinha dados, restaure o `.env` original e sua APP_KEY. `--fix-key` não cria um `.env` novo automaticamente; `--init-env` prepara um modelo para uma instalação nova. O arquivo é procurado ao lado de `artisan`, tanto em `public_html` quanto na estrutura privada `poseitech`.

Use `/opt/alt/php83/usr/bin/php` também nos comandos Artisan e no cron. Se esse executável não existir, use o caminho compatível informado pelo instalador ou confirme o caminho com a hospedagem. Não reduza a exigência de PHP do Laravel para 8.1.

[A Hostinger explica a diferença entre o PHP do site e o PHP do SSH/Composer](https://www.hostinger.com/support/5792082-how-to-solve-common-composer-issues-at-hostinger/).

## Composer informa dezenas de incompatibilidades com PHP 8.1

Todos esses erros têm a mesma causa: `composer install` está sendo executado pelo PHP 8.1 do terminal. Use explicitamente o PHP 8.3 também para o Composer:

```sh
cd /home/u382808979/domains/crm.poseitech.com.br/public_html
/opt/alt/php83/usr/bin/php -v
/opt/alt/php83/usr/bin/php /usr/local/bin/composer2 install --no-dev --optimize-autoloader
/opt/alt/php83/usr/bin/php scripts/hostinger.php --install
```

Se `/usr/local/bin/composer2` não existir e você tiver enviado o arquivo `composer.phar` para a pasta do projeto, substitua somente a linha do Composer por:

```sh
/opt/alt/php83/usr/bin/php composer.phar install --no-dev --optimize-autoloader
```

Não edite o código de `composer.phar`, não execute `composer update` para contornar esse erro e não use `--ignore-platform-reqs`: o projeto e suas dependências precisam realmente executar em PHP 8.2+. A autodetecção de versão de `scripts/hostinger.php` vale para esse script; ela não muda o comando global `composer` do SSH.

## Correção da instalação atual: APP_KEY ausente

O log informado mostra `MissingAppKeyException`. Envie o arquivo atualizado `scripts/hostinger.php` para a instalação e execute no terminal:

```sh
cd /home/u382808979/domains/crm.poseitech.com.br/public_html
/opt/alt/php83/usr/bin/php scripts/hostinger.php --fix-key
```

O comando limpa caches gerados, mantém qualquer chave já existente no `.env` e gera uma apenas quando estiver ausente. Não altera banco, usuários, senhas ou credenciais. Se a empresa já usou criptografia em outra instalação, restaure sua APP_KEY original antes de executá-lo. Não publique a chave nem copie uma chave de exemplo compartilhada.

Sem o script, use `php artisan config:clear`, confira o campo `APP_KEY` no `.env`, execute `php artisan key:generate --force` **somente se estiver vazio** e finalize com `php artisan config:cache`.

## Nova instalação / organização das pastas

Use `poseitech/` para a aplicação privada e `public_html/` para o conteúdo de `public/`, mantendo as duas pastas no mesmo nível. O `public/index.php` atualizado reconhece essa estrutura e também a estrutura Laravel original. Não remova arquivos de outro site: use um domínio/subdomínio dedicado.

1. Selecione PHP **8.2 ou superior** no hPanel, com `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `dom` e `xml` habilitados. Use a mesma versão no terminal.
2. Crie um banco MySQL e usuário no hPanel. Copie `poseitech/.env.example` para `poseitech/.env` e preencha domínio HTTPS e dados reais do banco. Se já existe uma instalação com dados, **preserve a APP_KEY existente** e suas credenciais.
3. No terminal SSH, entre em `poseitech` e execute:

```sh
/opt/alt/php83/usr/bin/php scripts/hostinger.php --install
/opt/alt/php83/usr/bin/php artisan poseitech:admin seu@email.com
```

O primeiro comando verifica requisitos, cria somente pastas necessárias, remove caches gerados, gera a chave apenas se estiver ausente, aplica migrations sem apagar registros, cria planos iniciais e recompila caches no servidor. Antes dele, instale as dependências usando o comando com PHP 8.3 da seção Composer acima, ou envie a pasta `vendor` completa. Não é necessário Node ou Vite.

4. Abra seu domínio e use **Começar agora**. Configure um cron a cada minuto com o caminho absoluto do PHP e de `poseitech/artisan schedule:run`. Configure SMTP antes de usar e-mails; `MAIL_MAILER=log` não envia mensagens reais.

## Se continuar aparecendo 500

Execute `php scripts/hostinger.php` e consulte o final de `poseitech/storage/logs/laravel-AAAA-MM-DD.log` (ou `laravel.log`) e o log PHP do hPanel. Mantenha `APP_DEBUG=false` no site. Envie somente o tipo/mensagem do erro, ocultando senhas, tokens e dados de clientes.

`storage/` e `bootstrap/cache/` precisam permitir escrita pelo usuário da hospedagem. Não use 777. Não envie o `.env`, banco SQLite ou caches do computador local. Se a mensagem mencionar `C:\xampp`, há cache local indevido no servidor: execute novamente a instalação acima.

Sem acesso SSH, os comandos precisam ser executados pelo terminal disponibilizado no seu plano ou pelo responsável pela hospedagem. Não há instalador web público que exponha comandos administrativos.

Referência: [estrutura de implantação indicada pela Hostinger](https://www.hostinger.com/br/support/6152127-como-implantar-deploy-o-laravel-8-na-hostinger/). Este projeto utiliza Laravel 12, portanto requer PHP 8.2+.
# Atualização: fiado por cliente

## Comprovantes automáticos e salvamento conjunto de categorias

Envie a migração `2026_09_28_000004_automatic_sale_receipts.php`, os templates `resources/views/emails/receipt.blade.php` e `receipt-text.blade.php` e os demais arquivos alterados. Execute `migrate --force` com PHP 8.3 e limpe os caches.

Cada venda concluída com cliente que tenha e-mail válido cria um comprovante na fila, mesmo sem consentimento para mensagens promocionais: é uma comunicação sobre a própria compra. A venda e o registro da fila são gravados juntos, e repetir a mesma venda não duplica o e-mail. Vendas sem cliente ou sem e-mail continuam funcionando, sem envio. Falhas de SMTP não desfazem a venda; use a fila para tentar novamente.

O envio automático é realizado pelo cron existente (`schedule:run` a cada minuto, que executa `poseitech:mail`). Configure e teste o SMTP de cada empresa. Sem cron, as mensagens ficam pendentes até processamento manual. O HTML contém itens, valores, pagamentos, fiado e links para o comprovante e, quando disponível, o portal. Também existe uma versão em texto simples.

O portal permanece limitado ao mês selecionado, mesmo que uma URL antiga contenha `scope=all`. As duas listas de categorias são salvas juntas pelo botão **Salvar todas as categorias**; um erro em qualquer lista impede alterações parciais.

## Categorias por direção, indicadores do fiado e comprovantes

Esta atualização não exige migração. Envie todos os arquivos alterados e limpe os caches de views/configuração. As listas de categorias agora são configuradas separadamente para entradas e saídas. Listas personalizadas da versão anterior são preservadas inicialmente nas duas direções; ajuste cada lista em **Configurar categorias da empresa**. Categorias históricas continuam nos filtros mesmo depois de removidas das opções de cadastro.

Os cartões por forma de pagamento mostram também a diferença entre recebido e gasto. O portal permite filtrar as compras pelo mês ou por todo o histórico, por fiado/quitadas/saldo da conta, status e número. Os indicadores mostram o fiado original das compras do mês, o restante dessas compras e o total de fiado em aberto; a seleção da lista não altera esses indicadores. Comprovantes identificam o valor pago com saldo da conta e sua devolução quando a venda é cancelada.

## Datas retroativas, categorias e portal permanente

Esta atualização não exige migração adicional. Envie os arquivos novos `app/Services/FlowCategories.php` e `app/Services/PortalLink.php`, além dos arquivos alterados, e limpe os caches de rotas, views e configuração.

O fluxo permite escolher hoje ou uma data anterior, no fuso da empresa. O lançamento entra no mês selecionado e conserva a data real de cadastro para auditoria. Categorias são configuradas por administradores em **Fluxo de caixa → Configurar categorias da empresa**. Categorias retiradas da lista deixam de aceitar novos lançamentos, mas permanecem no filtro do histórico. Os cartões por forma de pagamento e os saldos respeitam o mês e a categoria selecionados.

Os links do portal não expiram, aparecem no perfil e podem ser revogados. Links antigos continuam funcionando, inclusive quando a antiga data de validade já passou. O link exibido no perfil usa uma assinatura segura baseada na chave da aplicação e no acesso atual do cliente; preservar a `APP_KEY` mantém esses links válidos. Revogar invalida tanto o link exibido como o link antigo e encerra o acesso das sessões do portal. Gerar novamente depois de revogar cria um novo acesso.

O portal mostra uma tabela de compras por mês, com número, valor e comprovante. Somente contas ainda em aberto aparecem em **Valores pendentes**, incluindo dívidas de meses anteriores. Créditos, cashback, saldo e extrato não são exibidos no portal; os registros permanecem preservados no sistema.

## SMTP Gmail, extrato agrupado e caixa obrigatório

Envie também `public/assets/layout-fixes.css`, `app/Services/CompanySmtp.php` e `resources/views/components/flow-statement.blade.php`, junto com os demais arquivos alterados. Limpe as views e configurações após atualizar. Esta etapa não cria novas tabelas.

Gmail: use `smtp.gmail.com`, e-mail completo como usuário, senha de aplicativo e remetente autorizado pela conta. Porta 465 usa SSL/TLS e 587 usa STARTTLS; o aplicativo corrige a combinação nas portas padrão e remove os espaços da senha de aplicativo Google. Referência: https://support.google.com/a/answer/176600 . Em Configurações, salve e clique em **Testar conexão SMTP salva**, depois tente uma mensagem da fila. O teste confirma conexão/autenticação, mas não garante aceitação de um remetente ou destinatário específico. A fila agora mostra a categoria da falha, sem guardar a senha ou a conversa SMTP no log.

Toda nova venda exige caixa aberto, inclusive Pix, cartão, fiado e saldo do cliente. Caixa é uma dependência obrigatória de Vendas. O extrato e o CSV têm uma linha por dia e forma de pagamento no fuso da empresa; a diferença corresponde às entradas menos as saídas daquele grupo. Abra o modo para conferir separadamente as origens das entradas e saídas.

## Depósitos, fluxo de caixa, equipe e fila de e-mails

Envie a migração `2026_09_28_000003_wallet_flow_and_team.php` junto com os novos controllers, serviços, views, rotas e arquivos `public/assets`. Execute `migrate --force` e limpe os caches com PHP 8.3 conforme os comandos abaixo. A aplicação avisa quando o banco ainda não recebeu a atualização.

- Em Nova venda, selecione **Depósito na conta do cliente**. O depósito quita primeiro os fiados mais antigos e mantém o excedente como saldo para compras futuras. Depósitos não contam como vendas; o uso do saldo não duplica entradas no fluxo. O cancelamento da compra devolve a parte paga com saldo à conta do cliente.
- **Fluxo de caixa** mostra o mês, saldo anterior/final, entradas, saídas, estornos, caixas e formas de pagamento, com extrato e CSV. Registre retiradas, despesas, aportes e o saldo inicial diretamente nessa tela. A abertura diária de caixa não é receita e não entra novamente no fluxo. O saldo considera apenas movimentações registradas no sistema; retiradas diretas não alteram o caixa operacional.
- Excluir alguém da equipe revoga o acesso e preserva as operações históricas. Usuários excluídos não ocupam o limite da equipe. Não é permitido remover o próprio acesso ou o último administrador ativo.
- Em Configurações, a fila permite enviar uma mensagem, tentar todas as pendentes/falhas ou cancelar a fila. Para enviar todas, mantenha a página aberta. O cron `poseitech:mail` continua processando pendentes automaticamente. A limpeza preserva o histórico e não interrompe mensagens já em envio. Uma falha de rede após aceitação pelo SMTP pode deixar o resultado incerto; confira com o destinatário antes de repetir uma mensagem nessa situação.


Se Produtos ou Equipe exibirem `Unknown column 'deleted_at'` ou `Unknown column 'must_change_password'`, envie também o arquivo `database/migrations/2026_09_28_000002_password_and_product_deletion.php` antes de executar os comandos abaixo. Esses erros indicam banco sem a migração correspondente. A migração aceita uma execução anterior parcialmente concluída, sem recriar colunas existentes. Confirme com `php artisan migrate:status`, usando o mesmo executável PHP 8.3 abaixo. Não use `migrate:fresh` em produção.

Envie também `public/assets/app.js`, `public/assets/app.css` e as views atualizadas. O layout inclui uma versão nos endereços desses arquivos para que o navegador carregue a busca e a máscara novas.

As atualizações seguintes também incluem exclusão de produtos preservando o histórico e troca obrigatória de senha. Após aplicar as migrações, todas as contas existentes deverão escolher uma nova senha no próximo acesso. Novos usuários e usuários cuja senha seja redefinida pela administração também deverão alterá-la. A recuperação por e-mail já permite definir a senha pessoal. Somente o suporte da plataforma pode alterar módulos, entrando na empresa pelo acesso de suporte. Administradores e funcionários podem trocar a própria senha em Configurações.

Depois de enviar os arquivos atualizados, execute na pasta que contém `artisan`:

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan view:clear
```

A migração mantém as vendas e os pagamentos existentes e prepara o saldo devedor por cliente. Clientes existentes recebem dia de vencimento 5; ajuste no cadastro quando necessário. Nos meses sem o dia escolhido (por exemplo, 31), o vencimento usa o último dia do mês. Preserve o `.env` e a `APP_KEY` atuais.


## Atualização: vendas, recompensas e catálogo (29/09)

Envie todos os arquivos alterados, incluindo `database/migrations/2026_09_29_000001_catalog_and_coupon_rewards.php`, `public/assets/catalog.css` e `public/assets/catalog.js`. Na pasta do `artisan`, execute:

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan view:clear
```

- **Venda:** marque Item avulso para informar nome, preço e quantidade sem criar cadastro. O leitor deve operar como teclado e finalizar com Enter; mantenha o foco em Leitor de barras. O campo Código do produto deve conter o código lido e ser exclusivo entre produtos ativos. Leituras repetidas somam a quantidade por leitura. Venda sem estoque exige confirmação e gera auditoria; o estoque pode ficar negativo para reposição posterior.
- **Cupons:** compra mínima maior que zero é o subtotal necessário para conquistar o benefício, uma vez por cliente e cupom, em novas vendas. O resgate ocorre em uma próxima compra de qualquer valor; o desconto fica limitado ao subtotal. Prazo, cliente exclusivo e limite global de usos continuam valendo. Cupom com compra mínima zero continua sendo um código livre. O histórico mostra conquista, estado do e-mail e venda de uso. Excluir arquiva o cupom e preserva histórico. Cancelar a compra que o originou revoga o benefício; cancelar o resgate libera o uso novamente se o benefício continuar válido.
- **Envios:** cliente identificado e com e-mail válido recebe a recompensa pela fila SMTP existente. Mantenha o cron `poseitech:mail` ativo. Estar na fila não significa que o SMTP já entregou a mensagem.
- **Catálogo:** em Configurações, personalize cor, título e apresentação, copie o link e habilite pedidos online. Requer módulo Pedidos. O carrinho envia uma solicitação, sem cobrar ou reservar estoque. Na tela Pedidos, confira os dados e registre a venda; selecione/cadastre o cliente se necessário e confirme os preços atuais com ele. O caixa precisa estar aberto. Frete e pagamento são combinados no atendimento. Pedidos online duplicados não geram duas vendas.


## Cupons em tempo real e histórico do catálogo

Envie também a migração `2026_09_29_000002_coupon_products_and_catalog_history.php`, os novos controllers/partials e os assets atualizados. Execute `migrate --force`, `config:clear`, `route:clear` e `view:clear` com `/opt/alt/php83/usr/bin/php` na pasta do `artisan`.

- Cupons são validados automaticamente ao digitar e ao mudar cliente, itens ou desconto. A prévia não consome usos; o fechamento valida novamente. Produto grátis abate o preço-base de uma unidade do produto configurado, que deve estar na venda. Adicionais são cobrados. Valor e percentual continuam disponíveis. A listagem mostra validade, status e quantidade de usos, com filtros.
- Para alterar o endereço do catálogo, o suporte entra na empresa e acessa Configurações > Catálogo > Link do catálogo. O link antigo deixa de funcionar; o novo deve ser compartilhado. A permissão é validada no servidor e a alteração é auditada.
- O carrinho é preservado no armazenamento local por empresa, sem guardar nome, telefone ou endereço. Recarregar a página recalcula preços atuais e ajusta quantidades ao estoque. Após envio confirmado, o carrinho é limpo.
- Meus pedidos usa um cookie privado persistente por empresa. Mostra os pedidos feitos neste navegador após esta atualização, os estados registrado/cancelado e o andamento em Pedidos. Não recupera compras antigas sem esse identificador, nem compartilha histórico entre dispositivos ou após limpeza dos cookies. A página retorna `Cache-Control: private, no-store`; mantenha o cache de página/CDN desabilitado para `/catalog/*` para não compartilhar dados de visitantes.


## Layout do catálogo e de Nova Venda

Esta revisão reorganiza o catálogo em formato de cardápio com categorias, produtos compactos, sacola lateral e identificação na etapa de finalizar. Nova Venda separa cliente, itens e pagamento; campos opcionais ficam em seções expansíveis. Pedidos do catálogo mostra apenas solicitações pendentes sem venda registrada, preservando o histórico dos demais pedidos.

Envie as views, `app/Http/Controllers/BusinessController.php`, `public/assets/catalog.css`, `public/assets/catalog.js` e o novo `public/assets/sale.css`. Execute `/opt/alt/php83/usr/bin/php artisan view:clear`. Esta revisão de layout não acrescenta migrações; as migrações anteriores continuam necessárias caso ainda não tenham sido aplicadas.
