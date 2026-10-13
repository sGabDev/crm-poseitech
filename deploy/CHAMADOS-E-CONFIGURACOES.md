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
- As atualizações aparecem ao abrir ou atualizar a página. Este fluxo usa mensagens de texto e alertas internos; não envia e-mails nem anexos.

## Configurações

A página está dividida em dados da empresa, módulos, integrações, fila de e-mails, equipe, automações, senha e auditoria.

Em **Configurações → Módulos da empresa**, o administrador da empresa pode marcar ou desmarcar **Conexão do WhatsApp** e **Gerenciar modelos de mensagem**, depois clicar em **Salvar conexões e mensagens**. Desativar o acesso não apaga históricos, modelos ou sessões existentes. Os demais módulos continuam sob controle do Super Admin. As permissões individuais da equipe continuam sendo aplicadas.

Mercado Pago e SMTP ficam em **Integrações e Mercado Pago**.
