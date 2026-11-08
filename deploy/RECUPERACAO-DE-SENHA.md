# Recuperação de senha

O fluxo utiliza o SMTP global da aplicação, separado do SMTP cadastrado por cada empresa em Configurações. Não precisa de worker de fila: o envio acontece na solicitação, com timeout de conexão de 15 segundos.

## Configuração em produção

Configure no `.env` privado do servidor, com as credenciais fornecidas pelo serviço de e-mail:

```dotenv
APP_URL=https://seu-dominio.com
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.seu-provedor.com
MAIL_PORT=465
MAIL_USERNAME=contato@seu-dominio.com
MAIL_PASSWORD="senha-do-smtp"
MAIL_FROM_ADDRESS=contato@seu-dominio.com
MAIL_FROM_NAME="PoseiTech CRM"
```

Para porta 587 com STARTTLS, use `MAIL_SCHEME=smtp`. O remetente deve ser autorizado pelo provedor. `APP_URL` precisa apontar para o endereço HTTPS público correto: os links são montados a partir dessa configuração, sem utilizar o Host recebido na requisição.

Depois de atualizar os arquivos e a configuração:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

`MAIL_MAILER=log` é destinado ao desenvolvimento: não entrega mensagens e registra o conteúdo do e-mail, incluindo o link de recuperação, no log. Não use esse modo, nem fallback para log, para entregar recuperação em produção.

## Comportamento

- `/forgot-password` recebe o e-mail, remove espaços externos e normaliza para minúsculas.
- A resposta pública é a mesma para conta inexistente, inativa, solicitação repetida ou falha de transporte. Isso evita revelar o cadastro de contas.
- Contas inativas não recebem links e não podem redefinir a senha.
- O broker permite um novo envio por conta a cada 60 segundos; a rota limita a três solicitações por minuto por IP.
- Os tokens são armazenados como hash, expiram em 60 minutos e são consumidos após uma redefinição válida. Um novo link substitui o anterior.
- A senha exige pelo menos dez caracteres, letras, números e confirmação. A rota de redefinição limita a cinco tentativas por minuto por IP.
- A troca encerra as sessões armazenadas no banco e renova o token de “lembrar de mim”. O usuário deve entrar com a nova senha.
- Falhas de transporte geram o evento de log `auth.password_reset_delivery_failed`, sem registrar a mensagem da exceção, credenciais ou link. O token do envio malsucedido é removido para permitir nova solicitação.

## Validação

```sh
php artisan test --filter=PasswordRecoveryTest
```

Após configurar o SMTP, valide manualmente com uma conta ativa de teste: solicitar, receber o e-mail (inclusive conferir spam), abrir o link, trocar a senha, entrar com a nova senha e tentar reutilizar o link. Os testes automatizados não enviam mensagens externas e não comprovam a entrega do provedor de produção.
