# WhatsApp por empresa (Baileys)

## Servidor separado

O pacote independente fica em `baileys-server/`. Copie somente essa pasta para o servidor de WhatsApp e siga `baileys-server/README.md`. Ela tem dependências, configuração e dados próprios, sem PHP ou banco do CRM. O arquivo `baileys-server.zip` contém o pacote pronto para transferência, sem segredos, sessões ou `node_modules`.

No CRM, configure `WHATSAPP_SERVICE_URL` com a URL HTTPS do servidor remoto e `WHATSAPP_SERVICE_TOKEN` com o mesmo token do serviço. Execute `php artisan config:clear`. O menu de bots permanece no CRM, enquanto as conexões e sessões ficam no servidor remoto.

As instruções abaixo também mantêm a opção local de desenvolvimento.


O menu **WhatsApp · Bots**, disponível ao administrador da empresa, permite criar um bot por empresa, gerar QR code, acompanhar a conexão, desconectar e escolher um bot para comprovantes automáticos. Uma empresa não acessa os bots nem os comprovantes de outra. O envio por e-mail continua pela fila SMTP existente. Configure o telefone do cliente com DDD (Brasil) ou código internacional completo.

## Instalação

Requer Node.js 22 ou superior, PHP e acesso a um processo Node permanente. Hospedagem PHP compartilhada sem processos Node não executa Baileys: nesse caso, execute este serviço em uma VPS privada e configure o CRM para acessá-lo por HTTPS autenticado.

Na raiz do projeto:

```sh
npm ci
npm ci --prefix baileys-server
php artisan migrate --force
php artisan config:clear
php artisan view:clear
npm run whatsapp
```

Configure no `.env` dois segredos aleatérios diferentes, de pelo menos 32 caracteres:

```dotenv
WHATSAPP_SERVICE_URL=http://127.0.0.1:3100
WHATSAPP_SERVICE_TOKEN=<segredo da API>
WHATSAPP_SESSION_KEY=<chave de criptografia das sessões>
WHATSAPP_SERVICE_HOST=127.0.0.1
WHATSAPP_SERVICE_PORT=3100
```

O Node carrega o `.env` a partir da pasta atual: execute sempre na raiz. A chave de sessão não deve mudar após conectar os números. Preserve-a no backup junto com `storage/app/private/whatsapp`. Tokens, chaves, QR codes e sessões não devem ser publicados. A API deve permanecer em loopback ou rede privada; não abra sua porta à internet. Para serviço remoto, use proxy HTTPS com restrição de rede.

## Processos permanentes

Mantenha **um único processo** `npm run whatsapp` por diretório de sessões, com reinício automático (systemd, PM2 em modo fork com uma instância, ou serviço Windows) e diretório de trabalho na raiz. Mantenha também `php artisan schedule:work` ativo. O scheduler processa novas tentativas de WhatsApp a cada minuto e mantém os demais agendamentos de e-mail. Em Linux, o cron Laravel `* * * * * cd /caminho/crm && php artisan schedule:run` também suporta as tarefas menores que um minuto; não configure os dois simultaneamente.

Sessões e chaves Signal são armazenadas em snapshots criptografados AES-256-GCM, com escrita serializada e substituição atémica. Há um arquivo de lock para impedir duas instâncias: após parada abrupta, confirme que nenhum processo usa o diretório, remova apenas a pasta vazia `storage/app/private/whatsapp/.service-lock` e reinicie. A conexão é restaurada automaticamente; logout pelo celular exige novo QR code.

## Uso e entregas

1. Abra WhatsApp · Bots, crie um bot e clique em **Conectar / Gerar QR code**.
2. No celular: WhatsApp → Dispositivos conectados → Conectar dispositivo.
3. Aguarde **Conectado** e selecione **Usar para comprovantes**.
4. Finalize uma venda com cliente identificado e telefone válido. O comprovante fica na fila sem depender da disponibilidade momentânea do WhatsApp.
5. A tela da venda também oferece **Enviar WhatsApp** e **Enviar comprovante por e-mail**. O botão do WhatsApp tenta enviar imediatamente, recupera falhas e mostra o resultado na venda; não duplica envios concluídos.

A primeira tentativa automática é iniciada depois de salvar a venda e finalizar a resposta da página, sem exigir um worker de fila. O agendador continua necessário para recuperar falhas e processar pendências, uma vez por minuto. As ações do menu retornam para páginas HTML; o JSON de status é usado somente pelas consultas em segundo plano.

## Limite de conexões do banco

A página consulta cada bot uma vez ao abrir e continua somente para conexões em andamento, a cada 12 segundos por até 2 minutos. Ao conectar, ficar indisponível ou atingir esse prazo, as consultas param. “Verificar conexão” inicia uma nova consulta. A aba oculta pausa as atualizações; as requisições são sequenciais e têm timeout.

O erro MySQL 1226 (`max_connections_per_hour`) significa que a hospedagem bloqueou novas conexões para esse usuário. O CRM apresenta uma página temporária com status 503 e pausa tentativas por 5 minutos, inclusive os agendamentos locais. Isso não altera nem remove o limite da hospedagem: é necessário aguardar a liberação ou solicitar sua revisão ao provedor. Não mantenha um agendador local e outro em produção acessando o mesmo banco sem necessidade.

Depois de atualizar os arquivos, execute `php artisan view:clear` e `php artisan config:clear`. Use apenas um agendador no servidor do CRM. As conexões Baileys permanecem no servidor separado.

São feitas até 10 tentativas para indisponibilidade do serviço, número inválido ou bot desconectado. Depois de corrigir a causa, use o botão da venda para recolocar uma falha na fila. Vendas canceladas e clientes anonimizados não são enviados. Envios pendentes preservam o bot escolhido no momento da venda.

Uma falha de rede durante o envio pode deixar a entrega **Sem confirmação**. O sistema não repete automaticamente nesses casos: confira a conversa no celular. “Enviado” significa aceito pelo WhatsApp, não confirma leitura ou entrega ao aparelho. A reserva persistente por empresa/venda evita reenvio após timeout do CRM ou reinício do serviço. Preserve `messages.enc`; removê-lo perde essa proteção.

Baileys conecta pelo WhatsApp Web e não é a API oficial da Meta. A conexão e um envio real só podem ser confirmados com o QR code escaneado em um número disponível. Referência técnica: https://github.com/WhiskeySockets/Baileys

## Verificação

```sh
npm run check
npm test
npm run test:whatsapp
```

A API autenticada `GET /health` verifica o serviço. Nenhum segredo da API é enviado ao navegador: todas as chamadas passam pelo servidor Laravel, com autenticação, permissão de administrador e escopo da empresa.
