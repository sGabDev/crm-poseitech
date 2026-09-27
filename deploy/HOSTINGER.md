# Instalação na Hostinger

## Correção da instalação atual: APP_KEY ausente

O log informado mostra `MissingAppKeyException`. Envie o arquivo atualizado `scripts/hostinger.php` para a instalação e execute no terminal:

```sh
cd /home/u382808979/domains/crm.poseitech.com.br/public_html
php scripts/hostinger.php --fix-key
```

O comando limpa caches gerados, mantém qualquer chave já existente no `.env` e gera uma apenas quando estiver ausente. Não altera banco, usuários, senhas ou credenciais. Se a empresa já usou criptografia em outra instalação, restaure sua APP_KEY original antes de executá-lo. Não publique a chave nem copie uma chave de exemplo compartilhada.

Sem o script, use `php artisan config:clear`, confira o campo `APP_KEY` no `.env`, execute `php artisan key:generate --force` **somente se estiver vazio** e finalize com `php artisan config:cache`.

## Nova instalação / organização das pastas

Use `poseitech/` para a aplicação privada e `public_html/` para o conteúdo de `public/`, mantendo as duas pastas no mesmo nível. O `public/index.php` atualizado reconhece essa estrutura e também a estrutura Laravel original. Não remova arquivos de outro site: use um domínio/subdomínio dedicado.

1. Selecione PHP **8.2 ou superior** no hPanel, com `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `dom` e `xml` habilitados. Use a mesma versão no terminal.
2. Crie um banco MySQL e usuário no hPanel. Copie `poseitech/.env.example` para `poseitech/.env` e preencha domínio HTTPS e dados reais do banco. Se já existe uma instalação com dados, **preserve a APP_KEY existente** e suas credenciais.
3. No terminal SSH, entre em `poseitech` e execute:

```sh
php scripts/hostinger.php --install
php artisan poseitech:admin seu@email.com
```

O primeiro comando verifica requisitos, cria somente pastas necessárias, remove caches gerados, gera a chave apenas se estiver ausente, aplica migrations sem apagar registros, cria planos iniciais e recompila caches no servidor. Instale as dependências com `composer install --no-dev --optimize-autoloader` ou envie a pasta `vendor` completa. Não é necessário Node ou Vite.

4. Abra seu domínio e use **Começar agora**. Configure um cron a cada minuto com o caminho absoluto do PHP e de `poseitech/artisan schedule:run`. Configure SMTP antes de usar e-mails; `MAIL_MAILER=log` não envia mensagens reais.

## Se continuar aparecendo 500

Execute `php scripts/hostinger.php` e consulte o final de `poseitech/storage/logs/laravel-AAAA-MM-DD.log` (ou `laravel.log`) e o log PHP do hPanel. Mantenha `APP_DEBUG=false` no site. Envie somente o tipo/mensagem do erro, ocultando senhas, tokens e dados de clientes.

`storage/` e `bootstrap/cache/` precisam permitir escrita pelo usuário da hospedagem. Não use 777. Não envie o `.env`, banco SQLite ou caches do computador local. Se a mensagem mencionar `C:\xampp`, há cache local indevido no servidor: execute novamente a instalação acima.

Sem acesso SSH, os comandos precisam ser executados pelo terminal disponibilizado no seu plano ou pelo responsável pela hospedagem. Não há instalador web público que exponha comandos administrativos.

Referência: [estrutura de implantação indicada pela Hostinger](https://www.hostinger.com/br/support/6152127-como-implantar-deploy-o-laravel-8-na-hostinger/). Este projeto utiliza Laravel 12, portanto requer PHP 8.2+.
