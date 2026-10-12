# Servidor Baileys independente

Copie **somente esta pasta** para o servidor de WhatsApp. Ela não depende de PHP, Laravel, banco do CRM nem dos arquivos da pasta superior. Requer Node.js 22 ou superior e acesso à internet. As sessões de todas as empresas são criadas e armazenadas neste servidor, separadas por empresa e bot.

O menu para criar bots e escanear QR code permanece no CRM. O CRM chama a API deste serviço; o navegador não recebe o token de acesso. Este pacote é o serviço de conexão, sem painel web próprio.

O CRM permite um bot por empresa. O servidor continua atendendo várias empresas, cada uma com suas próprias credenciais. Depois de criar o bot, a tela oferece conexão, pausa da automação, alteração do nome e recuperação por novo QR code.

## Instalar e iniciar

Dentro desta pasta, execute:

```sh
npm ci --omit=dev
npm run setup
npm start
```

`npm run setup` cria um `.env` com dois segredos diferentes e não sobrescreve um arquivo existente. O serviço lê o `.env` desta pasta, independentemente do diretório de onde for iniciado.

```dotenv
WHATSAPP_SERVICE_TOKEN=<token compartilhado com o CRM>
WHATSAPP_SESSION_KEY=<chave privada somente deste servidor>
WHATSAPP_SERVICE_HOST=127.0.0.1
WHATSAPP_SERVICE_PORT=3100
WHATSAPP_DATA_DIR=./data
WHATSAPP_LOG_LEVEL=warn
```

As sessões são gravadas em `data/`, com criptografia AES-256-GCM. Caminhos relativos de `WHATSAPP_DATA_DIR` são resolvidos a partir desta pasta. Preserve a chave e o diretório de dados nos backups. Não troque a chave após conectar os números.

## Conectar o CRM em outro servidor

Configure um domínio HTTPS para este serviço, por exemplo `https://whatsapp.suaempresa.com`. Use um proxy reverso para `127.0.0.1:3100` e libere o acesso apenas ao IP de saída do CRM. O arquivo `nginx.conf.example` contém o bloco de proxy para inserir no servidor HTTPS já configurado. O certificado TLS e o DNS devem corresponder ao seu domínio.

No `.env` **do CRM**, configure:

```dotenv
WHATSAPP_SERVICE_URL=https://whatsapp.suaempresa.com
WHATSAPP_SERVICE_TOKEN=<mesmo token do .env do servidor Baileys>
```

No servidor do CRM:

```sh
php artisan config:clear
```

Mantenha o agendador Laravel funcionando no servidor do CRM. A primeira tentativa ocorre após a venda; o agendador recupera as tentativas que falharem. O botão de envio na venda também tenta imediatamente e mostra o resultado. Não execute PHP ou o agendador no servidor Baileys.

Depois, no CRM: **WhatsApp · Bots → Criar bot → Conectar / Gerar QR code**. Escaneie pelo WhatsApp do celular e escolha **Usar para comprovantes**. Cada empresa usa suas próprias conexões no mesmo serviço.

## Manter o processo ativo

Execute uma única instância por diretório de dados. Para Linux, há um modelo systemd em `poseitech-baileys.service.example`. Exemplo para uma instalação em `/opt/poseitech-baileys`, executando os comandos administrativos como root:

```sh
useradd --system --home /opt/poseitech-baileys --shell /usr/sbin/nologin baileys
mkdir -p /opt/poseitech-baileys/data
chown -R baileys:baileys /opt/poseitech-baileys
chmod 600 /opt/poseitech-baileys/.env
cp /opt/poseitech-baileys/poseitech-baileys.service.example /etc/systemd/system/poseitech-baileys.service
systemctl daemon-reload
systemctl enable --now poseitech-baileys
journalctl -u poseitech-baileys -f
```

Copie os arquivos para esse caminho e execute a instalação antes de ativar o serviço. Ajuste `ExecStart` se `node` estiver em outro caminho. Se o usuário `baileys` já existir, pule `useradd`.

Também é possível usar Docker:

```sh
npm run setup
docker compose up -d --build
docker compose logs -f
```

Nesse modo, os dados ficam no volume persistente `sessions`. Não use `docker compose down -v` se quiser preservar as conexões. O proxy HTTPS roda no host e acessa a porta local 3100. Se o host não tiver Node, crie o `.env` a partir de `.env.example` com dois segredos aleatórios de pelo menos 32 caracteres antes de iniciar Docker.

## API

Todas as rotas exigem `Authorization: Bearer <WHATSAPP_SERVICE_TOKEN>`:

| Método | Rota | Função |
|---|---|---|
| GET | `/health` | Verificar serviço |
| GET | `/sessions/{empresa}/{bot}` | Consultar status, telefone e QR code PNG em data URL |
| POST | `/sessions/{empresa}/{bot}/connect` | Criar/restaurar conexão e gerar QR code |
| POST | `/sessions/{empresa}/{bot}/disconnect` | Encerrar sessão |
| POST | `/sessions/{empresa}/{bot}/reconnect` | Encerrar a sessão anterior e gerar um novo QR code |
| POST | `/sessions/{empresa}/{bot}/messages` | Enviar `{ "id": "123", "phone": "5511999999999", "text": "Comprovante..." }` |

Empresa, bot e mensagem devem ter IDs numéricos positivos. O CRM controla a autorização de cada empresa. O token dá acesso administrativo a todas as sessões do serviço; use um serviço separado para cada instalação independente de CRM.

O serviço reconecta sessões após reinício. Logout pelo celular exige novo QR code. Envios com confirmação incerta ficam em `unknown` e não são repetidos automaticamente. Preserve `messages.enc` para manter a proteção contra duplicidade.

Para trocar o número ou recuperar uma sessão, use “Trocar número ou recuperar conexão → Gerar novo QR code” no CRM. A reconexão encerra o socket anterior e impede que eventos antigos restaurem credenciais descartadas. Preserve `.env` e `data/` ao atualizar o serviço; reinicie o processo depois de enviar os arquivos novos.

A trava `data/.service-lock` é renovada enquanto o processo está ativo. Após uma parada abrupta, a próxima inicialização recupera automaticamente uma trava sem atualização há 30 segundos; a inicialização pode aguardar até 35 segundos. Uma segunda instância ativa é bloqueada. Pare a versão antiga antes de atualizar, pois ela não renova a trava.

### BlazeHosting

Envie o conteúdo desta pasta para `/home/container`, mantendo seu `.env` e `data/`. Configure o arquivo principal (`MAIN_FILE`) como `server.mjs`. O painel executa `npm install` antes de iniciar, instalando também `proper-lockfile`.

Para acessar a API pela porta disponibilizada pela hospedagem, configure `WHATSAPP_SERVICE_HOST=0.0.0.0` e `WHATSAPP_SERVICE_PORT` com a porta atribuída no painel. No CRM, use a URL HTTPS que encaminha para essa porta e o mesmo token de acesso. A chave de sessão continua somente no servidor Baileys.

Se estiver atualizando uma versão com trava antiga, deixe o servidor parado por pelo menos 30 segundos antes de iniciar. Não remova os arquivos de sessão nem altere a chave de criptografia para corrigir uma trava.

## Migrar conexões locais existentes

Para começar com novos números, use o `.env` gerado e o diretório de dados vazio. Para preservar conexões já existentes, pare a instância antiga, copie o conteúdo de `storage/app/private/whatsapp/` do CRM para `data/` neste servidor e mantenha a mesma `WHATSAPP_SESSION_KEY`. Não copie a pasta de lock. Configure no CRM o token e a URL do novo servidor antes de retomar os envios. Nunca mantenha as duas instâncias operando as mesmas sessões.

## Verificar

```sh
npm run check
npm test
```

O QR code e o envio real precisam ser validados com um WhatsApp conectado. Baileys usa WhatsApp Web; referência do projeto: https://github.com/WhiskeySockets/Baileys.
