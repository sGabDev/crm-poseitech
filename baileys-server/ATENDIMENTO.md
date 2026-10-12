# Central de atendimento WhatsApp

Abra **WhatsApp · Atendimento** no menu do CRM (`/whatsapp/atendimento`). A conexão do único bot da empresa continua em **WhatsApp · Bots**. A central usa o mesmo número para atender os clientes e exibir os comprovantes enviados.

## Atualizar

1. Atualize os arquivos do CRM, incluindo as novas views, assets, controller, serviço e migration.
2. Execute no CRM:

   ```sh
   php artisan migrate --force
   php artisan optimize:clear
   ```

3. Pare o processo Baileys e envie o conteúdo atualizado de `baileys-server/` (ou `baileys-server.zip`) para o outro servidor. **Preserve `.env` e `data/`**. O arquivo novo `inbox.mjs` é obrigatório. Inicie com `npm start` ou `MAIN_FILE=server.mjs` na BlazeHosting.
4. Abra a central. Se o bot estiver desconectado, conecte em WhatsApp · Bots. Não é necessário cadastrar outro bot.

A migration desta alteração já foi aplicada ao banco configurado no ambiente de desenvolvimento. Outros bancos precisam executar o comando acima.

Para anexos de até 8 MB, configure PHP com `upload_max_filesize=8M` e `post_max_size=12M` ou superiores. O proxy do CRM e o proxy do serviço devem aceitar requisições de pelo menos 12 MB (o serviço recebe base64). Não exponha o diretório privado de uploads.

## Atendimento

A central ocupa toda a janela, sem sidebar, cabeçalho ou rodapé do CRM. O botão de voltar no painel de conversas retorna ao CRM. Somente o histórico, a lista e a ficha lateral têm rolagem quando necessário.

As respostas externas recebem automaticamente `*Nome do atendente:*` e duas quebras de linha antes da mensagem; o nome vem do usuário autenticado e não é repetido em tentativas do mesmo envio. Notas internas continuam sem assinatura externa. A tela interpreta negrito, itálico, riscado, código, monoespaçado, listas e citações, e oferece botões para inserir essas marcações no editor. Os modelos de respostas rápidas têm busca e prévia formatada.

Um tique cinza indica enviada, dois cinza entregue e dois azuis lida pelo cliente. Mensagens recebidas mostram a leitura depois que o serviço aceita a solicitação de marcar como lida. Os indicadores dependem dos recibos disponibilizados pelo WhatsApp e das configurações de privacidade do contato; não é possível inferir uma leitura que não foi confirmada. A sincronização permanece a cada 30 segundos enquanto a aba está visível.

- Lista de até 100 conversas recentes por filtro, busca por nome/telefone, filtro de status, não lidas, responsável atual e tag.
- Histórico paginado, busca no texto e atualização automática a cada 30 segundos enquanto a aba está visível. Cada empresa tem cursor e trava de sincronização para evitar importações duplicadas.
- Envio de texto, imagens, PDF, texto, áudio e vídeo até 8 MB. Imagens são enviadas como imagem; os demais anexos como documento. Áudios, vídeos e imagens recebidos podem ser abertos no próprio chat. Arquivos expirados no WhatsApp podem não estar disponíveis para download.
- Tags (até 15), responsável da equipe, status em aberto/aguardando/resolvido. Mensagens novas reabrem atendimentos resolvidos.
- Notas internas apenas no CRM, respostas rápidas compartilhadas pela empresa e rascunhos mantidos enquanto esta página estiver aberta.
- Cadastro de cliente a partir do contato ou vínculo pelo código de cliente existente. As tags salvas na conversa também atualizam o cliente vinculado.
- Mensagens enviadas pelo celular e comprovantes aparecem no mesmo histórico quando disponibilizados pelo WhatsApp. Confirmações de entrega/leitura dependem dos recibos recebidos.
- Edições e exclusões recebidas do WhatsApp atualizam o texto do histórico. Não há exclusão nem edição remota pela central.

O atendimento é de **conversas individuais**. Grupos, chamadas, status e mensagens temporárias não são ferramentas de atendimento desta tela. O conteúdo de visualização única não é armazenado. O histórico antigo depende do que o WhatsApp entregar na sincronização; não é possível garantir uma cópia de todo o histórico do celular. Algumas contas só entregam o histórico inicial após uma nova vinculação pelo QR code; a central não desconecta o número automaticamente.

## Permissões e armazenamento

Administradores têm acesso. Funcionários precisam de **Clientes · leitura** para visualizar; **Clientes · escrita** para responder, cadastrar contatos, marcar como lido e alterar o atendimento. Gerenciar QR code e conexões continua restrito aos administradores. As consultas e downloads são sempre filtrados pela empresa autenticada.

O Baileys guarda eventos e metadados de mensagens criptografados em `data/inbox-{empresa}-{bot}.enc`, com a mesma chave de sessão. O CRM importa o histórico para `whatsapp_conversations` e `whatsapp_chat_messages`. Anexos do CRM ficam no disco privado `local`, sob `whatsapp/{empresa}`. Faça backup do banco, dos anexos, de `.env` e de `data/`. O histórico não é removido automaticamente; monitore espaço e tamanho dos snapshots conforme o volume de mensagens.

Cada envio tem uma chave única. Repetir uma requisição não cria outro envio. Em caso de timeout sem confirmação, a mensagem fica **Sem confirmação** e não é reenviada automaticamente; confira no celular. Falhas explícitas antes do envio permitem **Tentar novamente** no chat. A sincronização pode reconciliar a confirmação quando o evento de saída chegar.

## API do serviço

Todas as rotas exigem `Authorization: Bearer WHATSAPP_SERVICE_TOKEN`. O token fica somente nos servidores, nunca no navegador.

| Método | Rota relativa a `/sessions/{empresa}/{bot}` | Uso |
| --- | --- | --- |
| GET | `/events?after=0` | Até 200 eventos por página, cursor e status da conexão |
| POST | `/chat-resolve` | `{ "phone": "5511999999999" }`, retorna o JID canônico |
| POST | `/chat-send` | `{ "requestKey": "UUID", "jid": "...@s.whatsapp.net", "text": "Olá" }`; opcional `file: {base64, mime, name}` |
| POST | `/chat-read` | `{ "jid": "...@s.whatsapp.net" }` |
| POST | `/chat-media` | `{ "jid": "...@s.whatsapp.net", "id": "ID_WHATSAPP" }`, retorna base64 |

Referência dos eventos e mensagens: [documentação do Baileys](https://github.com/WhiskeySockets/Baileys).

## Verificar

```sh
npm test
npm test --prefix baileys-server
```

Os testes usam banco SQLite em memória e sockets simulados; não enviam mensagens reais. A validação com QR code, celular e mídia real deve ser feita depois da atualização do serviço remoto.
