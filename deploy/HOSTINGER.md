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

Depois de enviar os arquivos atualizados, execute na pasta que contém `artisan`:

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan view:clear
```

A migração mantém as vendas e os pagamentos existentes e prepara o saldo devedor por cliente. Clientes existentes recebem dia de vencimento 5; ajuste no cadastro quando necessário. Nos meses sem o dia escolhido (por exemplo, 31), o vencimento usa o último dia do mês. Preserve o `.env` e a `APP_KEY` atuais.
