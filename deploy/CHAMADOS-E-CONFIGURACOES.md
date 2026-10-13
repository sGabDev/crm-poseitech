# Chamados e configurações

Após publicar os arquivos, execute na raiz do CRM:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan view:cache
```

## Atendimento

- A empresa acessa **Suporte técnico** no menu (`/support`) para abrir chamados, consultar respostas e acompanhar o status.
- O Super Admin acessa **Central de chamados** (`/admin/tickets`) para pesquisar solicitações de todas as empresas, responder, assumir atendimentos e alterar o status.
- Cada chamado registra autor, data, respostas e mudanças de status. Há indicação de respostas não lidas e alertas internos para a empresa.
- Chamados encerrados precisam ser reabertos antes de receber novas respostas.
- Usuários ativos da mesma empresa compartilham seus chamados. Outras empresas não têm acesso. Empresas bloqueadas ou com assinatura expirada continuam podendo procurar suporte.
- A conversa abre nas últimas 30 mensagens. Suba para carregar mensagens antigas sem recarregar a página. A atualização de novas respostas ocorre enquanto a aba está visível e em foco, entre 15 e 60 segundos, com pausa progressiva em falhas.
- É possível anexar até 5 arquivos de 5 MB por mensagem: JPG, PNG, WebP, PDF, TXT, Word, Excel e ZIP. Fotos têm prévia. Downloads exigem autenticação e acesso ao chamado; os arquivos ficam em `storage/app/private/support`.
- Configure PHP com `upload_max_filesize=5M`, `post_max_size=30M`, `max_file_uploads=5` ou superior, e ajuste o limite do proxy para 30 MB. Inclua esse diretório no backup. Os chamados usam alertas internos; não enviam e-mails de suporte.

## Configurações

A página está dividida em dados da empresa, módulos, integrações, fila de e-mails, equipe, automações, senha e auditoria.

**Todos os módulos**, incluindo **Conexão do WhatsApp** e **Gerenciar modelos de mensagem**, são ativados ou desativados exclusivamente pelo **Super Admin**. Entre na empresa pelo acesso de suporte e abra **Configurações → Módulos da empresa**. O administrador da empresa vê o estado dos módulos e pode solicitar alterações pelo suporte, mas não pode alterar a ativação. Desativar o acesso não apaga históricos, modelos ou sessões existentes. As permissões individuais da equipe continuam sendo aplicadas.

Mercado Pago e SMTP ficam em **Integrações e Mercado Pago**.

Para os envios automáticos e processamento para várias empresas, siga [ENVIOS-E-ESCALA.md](ENVIOS-E-ESCALA.md).
