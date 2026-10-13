# Envios automáticos e operação com muitas empresas

## Publicação

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

A migração `000008` cria os anexos dos chamados e índices das filas. Faça backup antes da atualização. Reinicie os processos de envios a cada publicação para carregar o código novo.

## Fluxos automáticos

- Ao concluir uma venda comum, os comprovantes são registrados nas filas de e-mail e WhatsApp, conforme os contatos e o bot automático disponíveis. Nenhum envio externo ocorre dentro da transação da venda.
- Ao vender com Mercado Pago, o link é encaminhado pelos canais disponíveis. Ao gerar um link para uma dívida, os envios também são solicitados automaticamente.
- A aprovação real de uma venda Mercado Pago concluída enfileira os comprovantes uma única vez. Aprovações de teste não fazem isso.
- Configure SMTP por empresa, e-mail/telefone do cliente e bot automático conectado. Sem SMTP, os e-mails aguardam configuração; não bloqueiam as demais empresas.
- A fila de e-mail também atende mensagens que campanhas e outros fluxos já enfileiram. Criar um rascunho de campanha ou um modelo livre não dispara mensagens para todos os clientes.

Os botões manuais continuam disponíveis para recuperação; não são necessários no fluxo normal. Nunca são refeitos envios já confirmados. Falhas temporárias explícitas do SMTP usam até oito tentativas com intervalo crescente. Timeouts ambíguos exigem conferência: SMTP não oferece uma garantia geral de envio único. WhatsApp reaproveita o identificador persistido no diário do Baileys e usa intervalo crescente de até uma hora; preserve o armazenamento do servidor Baileys. Envios com estado `unknown` não são repetidos automaticamente.

## Opção básica: cron

Com `DELIVERY_DAEMON=false`, mantenha um único cron Laravel:

```cron
* * * * * cd /caminho/crm && php artisan schedule:run >> /var/log/crm-scheduler.log 2>&1
```

O agendador processa as filas a cada minuto, sem sobreposição. Não é preciso manter a página de vendas ou configurações aberta. Os comandos antigos `poseitech:mail` e `poseitech:whatsapp` continuam disponíveis para diagnóstico.

## Serviço contínuo e partições

Para reduzir a espera e aumentar a vazão, instale `poseitech:deliver` como serviço supervisionado. Exemplo de Supervisor, ajustando caminhos e usuário:

```ini
[program:crm-delivery]
directory=/var/www/crm
command=/usr/bin/php artisan poseitech:deliver --shards=4 --shard=%(process_num)s --limit=100 --sleep=3
process_name=%(program_name)s_%(process_num)02d
numprocs=4
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm-delivery.log
stopwaitsecs=180
```

Após instalar e verificar os processos, configure `DELIVERY_DAEMON=true` no CRM, execute `php artisan config:cache` e reinicie os serviços. Isso desativa apenas o processamento agendado de envios. Mantenha o cron para reconciliação Mercado Pago e outras automações. Sem serviço contínuo, deixe a opção como `false`.

Todos os processos devem usar a mesma quantidade de partições, com índices de `0` até `shards-1`. Cada empresa pertence a uma partição pelo seu ID. Cada lote atende até cinco mensagens por empresa e mantém um cursor de rodízio entre empresas, para que uma campanha grande não monopolize a fila. Aumente os processos gradualmente conforme métricas reais, limites SMTP e capacidade do Baileys.

## Banco e infraestrutura

- Use MySQL com limites de conexões adequados à soma dos processos PHP, workers e conexões administrativas. O número de empresas, isoladamente, não determina a concorrência.
- Para múltiplos servidores do CRM, use Redis compartilhado para cache e locks (`CACHE_STORE=redis`) e sessões (`SESSION_DRIVER=redis`), com a extensão PHP correspondente. O processamento usa reivindicação atômica dos e-mails/WhatsApp e locks por envio Mercado Pago. Não mantenha caches de locks isolados entre servidores.
- As conexões SQL são liberadas durante SMTP, envio de comprovantes WhatsApp e espera ociosa do serviço. Verificações de estrutura do banco ficam em cache local por 60 segundos. O histórico dos chamados usa cursor e blocos de 30 mensagens, sem carregar toda a conversa.
- Os anexos ficam fora do banco. Em vários servidores web, compartilhe o armazenamento privado para que todos possam entregar os mesmos arquivos. Monitore espaço em disco e retenção de anexos.
- Monitore a idade da mensagem pendente mais antiga, tamanho das filas, falhas por canal, tempo das consultas, conexões MySQL, uso de memória/PHP e sessões do Baileys. Mensagens SMTP interrompidas ficam sinalizadas para conferência; não são repetidas cegamente.

Os testes automatizados validam isolamento, filas, rodízio, partições, retentativas e idempotência. **Não foi executado teste de carga com 500 empresas concorrentes.** Antes de produção nesse volume, teste em homologação com os limites reais de infraestrutura e provedores; não há garantia de capacidade baseada apenas na quantidade de cadastros.
