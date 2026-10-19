# PoseiTech CRM

Aplicação em Laravel 12, com Blade, CSS e JavaScript servidos diretamente de `public/assets`. Não exige compilação de frontend para funcionar.

## Requisitos e convenções

- PHP 8.2 ou superior, compatível com as versões fixadas em `composer.lock`, e Composer 2.
- Extensões exigidas pelas dependências, incluindo `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `PDO`, `session`, `tokenizer` e `xml`. Use `pdo_mysql` para MySQL/MariaDB ou `pdo_sqlite` para SQLite.
- Banco de dados e credenciais próprios de cada ambiente. Os testes estão configurados para SQLite em memória; valide o banco escolhido antes de implantar.
- Permissão de escrita para a aplicação em `storage/` e `bootstrap/cache/`.
- Node.js e npm apenas para as verificações opcionais de JavaScript.

Execute os comandos na raiz do projeto, onde está `artisan`. `php` e `composer` representam os executáveis disponíveis no PATH. Se não estiverem no PATH, use seus caminhos reais; com um arquivo PHAR, substitua `composer` por `php composer.phar`. No PowerShell, um executável com caminho entre aspas precisa do operador `&` antes do caminho. Nenhum comando depende de XAMPP ou de um diretório específico.

Confira `php -v`, `php --ini` e `composer --version`. O PHP do terminal e o do servidor web podem ter versões e extensões diferentes; ambos precisam atender aos requisitos.

## Instalação após clonar

### 1. Instalar as dependências

```sh
composer install
composer check-platform-reqs
```

A pasta `vendor/` não é versionada. Clonar o repositório e criar o `.env` não basta: sem `vendor/autoload.php`, a aplicação retorna erro 500. Use `install` para respeitar o lockfile, sem trocar versões com `update`.

### 2. Preparar o ambiente

Copie `.env.example` para `.env` apenas se o arquivo ainda não existir. Pode usar o gerenciador de arquivos ou o comando abaixo, válido com PHP no PATH em Windows, Linux e macOS:

```sh
php -r "if (!file_exists('.env')) { copy('.env.example', '.env'); }"
```

Para desenvolvimento com o servidor embutido:

```dotenv
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
SESSION_SECURE_COOKIE=false
SESSION_DOMAIN=null
```

Ajuste `APP_URL` ao endereço e à porta usados. Em HTTPS, use `SESSION_SECURE_COOKIE=true`. Ative debug apenas em um ambiente privado de desenvolvimento. Mantenha um `.env` por ambiente; não copie automaticamente credenciais ou caminhos de produção para desenvolvimento.

### 3. Configurar o banco

O `.env.example` usa MySQL e armazena sessões e cache no banco. Crie uma base dedicada e configure `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` com os valores do seu serviço. Para MariaDB, o projeto também oferece `DB_CONNECTION=mariadb`. O host pode ser local, um serviço de contêiner ou um endereço fornecido pela hospedagem.

Para usar SQLite, defina `DB_CONNECTION=sqlite` e remova a linha `DB_DATABASE` do `.env`, permitindo usar o caminho padrão `database/database.sqlite`. Remova também `DB_URL` se existir, para não substituir essa configuração. Crie o arquivo sem sobrescrever um banco existente:

```sh
php -r "if (!file_exists('database/database.sqlite')) { touch('database/database.sqlite'); }"
```

A pasta `database/` e o arquivo SQLite precisam permitir escrita. Se preferir outro local, defina `DB_DATABASE` com o caminho absoluto válido naquele ambiente, sem reaproveitar caminhos de outro computador.

### 4. Inicializar uma instalação nova

Gere a chave somente quando `APP_KEY` estiver vazia e não houver dados criptografados de uma instalação anterior:

```sh
php artisan key:generate
```

Para uma instalação existente, restaure a chave original. Ela protege dados criptografados e participa das assinaturas dos links do portal.

Depois de conferir que o banco selecionado é o correto:

```sh
php artisan config:clear
php artisan migrate --seed
php artisan serve
```

Abra o endereço informado pelo servidor, normalmente `http://127.0.0.1:8000`, e acesse `/register`. Não há senha padrão. Para criar o administrador da plataforma:

```sh
php artisan poseitech:admin admin@example.com
```

Substitua o e-mail; a senha será solicitada no terminal. O servidor embutido é destinado ao desenvolvimento.

## Publicação em servidor

Para dimensionamento de conexões, pool compartilhado por instância PHP, limites e validação com MySQL em produção, siga [deploy/MYSQL.md](deploy/MYSQL.md). As variáveis de pool exigem um proxy instalado e validado; não ativam um pool apenas por existir no `.env`.

Use um servidor web com PHP compatível. Aponte a raiz pública do site para `public/`. No Apache, habilite o processamento do `.htaccess` e a reescrita de URLs; no Nginx ou outro servidor, configure o encaminhamento das rotas para `public/index.php`. O Nginx não interpreta `.htaccess`.

Configure no ambiente de destino:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://crm.example.com
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
```

Substitua o domínio de exemplo, configure o banco, SMTP e HTTPS. Preserve a `APP_KEY` ao migrar uma instalação existente. Instale as dependências no destino ou em um ambiente de build compatível:

```sh
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan config:clear
php artisan migrate --force
```

Em uma instalação nova, gere primeiro a chave conforme a seção anterior, usando `php artisan key:generate --force`, e inicialize os planos com `php artisan db:seed --force`. Não regenere a chave em atualizações.

Após configurar o ambiente e o banco:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`storage/` e `bootstrap/cache/` precisam permitir escrita pelo usuário que executa PHP. Ajuste proprietário e permissões conforme o sistema; não use permissões universais de escrita. Não exponha `.env`, `vendor/`, backups ou bancos pela raiz pública.

Configure o agendador a cada minuto. Em sistemas com cron, substitua os caminhos do exemplo pelos caminhos absolutos reais:

```cron
* * * * * /caminho/do/php /caminho/do/projeto/artisan schedule:run
```

No Windows, crie uma tarefa recorrente no Agendador de Tarefas com o executável PHP como programa, o caminho de `artisan` e `schedule:run` como argumentos, e a raiz do projeto como diretório inicial. Em contêineres ou plataformas gerenciadas, configure a tarefa equivalente no ambiente da aplicação.

Mantenha backups do banco, de `storage/app` e da `APP_KEY`, além das configurações necessárias para restaurar o serviço. Não habilite cache compartilhado de página/CDN nas páginas autenticadas, no portal ou em `/catalog/*`.

Instruções para hospedagem compartilhada e o instalador incluído: [deploy/HOSTINGER.md](deploy/HOSTINGER.md).

## Atualizar uma instalação existente

Faça backup antes de alterar código e banco. Publique uma versão completa e consistente, incluindo assets e migrations, preservando `.env`, uploads e banco. Se necessário, use `php artisan down` durante a atualização e `php artisan up` após concluí-la.

Execute a sequência de instalação de dependências, limpeza de configuração, migrations e reconstrução dos caches descrita em Publicação. Execute `php artisan route:clear` e `php artisan view:clear` antes de reconstruir esses caches. Não copie caches gerados em outro ambiente. Não use `migrate:fresh`, pois apaga tabelas.

Confira `php artisan migrate:status`, a página de login, o acesso autenticado, assets e as operações necessárias no banco de destino. Verifique SMTP e agendamento separadamente; abrir o login não comprova essas integrações.

## Diagnóstico

| Sintoma | Verificação e correção |
| --- | --- |
| Erro 500 com `vendor/autoload.php` ausente | Execute `composer install` na raiz do projeto. |
| `MissingAppKeyException` | Confira o `.env`; restaure a chave original em instalações existentes ou gere uma chave para uma instalação nova. Limpe o cache de configuração. |
| PHP incompatível ou extensão ausente | Confira o PHP do terminal e do servidor web; execute `composer check-platform-reqs`. Não ignore os requisitos da plataforma. |
| Falha de conexão com banco | Confira driver PDO, serviço, host, porta, credenciais e permissões de acesso de rede. |
| Tabela ou coluna ausente | Confira `php artisan migrate:status` e aplique migrations pendentes na base correta. |
| Login não mantém sessão ou erro 419 | Confira cookies, domínio, HTTP/HTTPS, driver de sessão e permissões; com sessões em banco, confira as migrations. |
| Caminho de outro computador nos erros | Limpe os caches de configuração, rotas e views e gere-os no destino. |
| Assets ou rotas retornam 404 | Confira raiz pública, arquivos publicados e configuração de reescrita do servidor. |

Consulte `storage/logs/laravel.log` ou os arquivos do canal de log configurado, além dos logs de PHP e do servidor web. Erros anteriores à inicialização do Laravel podem aparecer apenas no servidor. Não publique credenciais ou dados de clientes ao compartilhar logs.

## Operação

- Toda nova venda exige caixa aberto. Configure equipe, módulos, fidelidade e SMTP conforme as permissões de cada perfil.
- Valores monetários são tratados em centavos. Venda, parcelas, estoque, recebimentos, pontos e estornos usam transações; vendas e recebimentos possuem controle de idempotência.
- Links do portal podem ser revogados e não expiram por prazo. Preserve a `APP_KEY` para manter as assinaturas válidas. O portal apresenta compras por mês e dívidas pendentes; comprovantes podem ser impressos ou salvos em PDF pelo navegador.
- Campanhas exigem consentimento. Comprovantes e cobranças são comunicações transacionais. A fila utiliza o SMTP de cada empresa; recuperação de senha utiliza o SMTP global. `MAIL_MAILER=log` não envia e-mails reais pelo mailer global.
- `poseitech:automate` atualiza alertas e `poseitech:mail` processa até 50 mensagens por execução. Configure o agendador; verifique o resultado antes de repetir mensagens com entrega incerta.
- O catálogo recebe solicitações sem cobrar ou reservar estoque. Confira os dados, os preços e o cliente ao registrar a venda.
- Planos limitam recursos e assinaturas têm prazo e bloqueio manual. A cobrança automática de assinaturas não está implementada.
- Cashback é crédito comercial. Relatórios são gerenciais, sem finalidade fiscal.

## Verificações de desenvolvimento

Instale as dependências de desenvolvimento com `composer install` e habilite `pdo_sqlite` para os testes:

```sh
php artisan config:clear
php artisan test
php vendor/bin/pint --test
npm run check
```

O `phpunit.xml` configura SQLite em memória. Execute testes em um ambiente dedicado, sem variáveis externas que substituam essa configuração. `npm run check` verifica sintaxe PHP e JavaScript; se necessário, configure `PHP_BINARY` com o caminho do PHP utilizado. Essas verificações não substituem a validação do servidor web, do banco e do SMTP escolhidos para implantação.
