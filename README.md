# PoseiTech CRM

Laravel 12 / PHP 8.2+, Blade, CSS e JavaScript locais, sem build de frontend.

## Executar

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Abra `/register` para cadastrar a empresa. Não há senha padrão. Crie o administrador da plataforma com `php artisan poseitech:admin seu@email.com` (senha solicitada no terminal).

Neste workspace, o servidor local usa **SQLite** em `database/database.sqlite`; nenhuma base de outro projeto foi alterada. Para MySQL/MariaDB, crie uma base exclusiva e preencha `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD`. Execute as migrations nessa base. Os testes automatizados utilizam SQLite isolado em memória.

## Operação

- Ative módulos e configure equipe, fidelidade e SMTP em Configurações. Venda com dinheiro exige caixa aberto quando esse módulo estiver ativo.
- Venda, parcelas, estoque, recebimentos, pontos e estornos usam transações; valores monetários são inteiros em centavos. Reenvio da venda e do formulário de recebimento usa chave de idempotência.
- Portal: gere um link no perfil do cliente; validade de 30 dias, revogável. Comprovantes possuem endereço aleatório. PDF é gerado pela opção **Imprimir / salvar PDF** do navegador.
- E-mails de campanha exigem consentimento; cobranças e comprovantes são transacionais. A fila usa o SMTP criptografado de cada empresa. Recuperação de senha usa o SMTP global definido no `.env`.
- Configure cron `* * * * * php /caminho/artisan schedule:run` (ou Agendador de Tarefas do Windows a cada minuto). `poseitech:automate` atualiza alertas; `poseitech:mail` processa até 50 e-mails. Campanhas automáticas de aniversário são rascunhos para revisão. Envios em falha não são repetidos automaticamente; um registro parado em `sending` requer verificação antes de reenviar.
- Planos limitam usuários, clientes, produtos, campanhas cadastradas e imagens. Assinaturas têm prazo e bloqueio manual; cobrança automática não está implementada, conforme o escopo.
- Cashback é crédito comercial. Utilização manual é registrada no perfil e deve corresponder ao desconto concedido na venda. Cancelamento pode deixar saldo de fidelidade negativo se o benefício já tiver sido usado.

## Validação e hospedagem

`php artisan test` valida isolamento, permissões, cálculos, estornos, caixa, limites, consentimento e portal. `php vendor/bin/pint --test` confere o padrão PHP. `npm run check` confere JavaScript e sintaxe PHP.

Na hospedagem, aponte o DocumentRoot para `public/`, use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, URL definitiva e `SESSION_SECURE_COOKIE=true`. Configure SMTP, cron e backups do banco, `storage/app` e `APP_KEY`. Não exponha `.env`, `vendor` ou o banco SQLite. Não use `migrate:fresh` em uma base com dados.

A validação automatizada foi feita em SQLite; MySQL, SMTP real e revisão visual em dispositivos ainda precisam ser homologados no ambiente de implantação. Relatórios financeiros e previsões são gerenciais, sem finalidade fiscal.
Instalação na Hostinger e correção de APP_KEY ausente: [deploy/HOSTINGER.md](deploy/HOSTINGER.md). O arquivo deploy/hostinger.env.example contém a configuração de produção sem credenciais reais.
