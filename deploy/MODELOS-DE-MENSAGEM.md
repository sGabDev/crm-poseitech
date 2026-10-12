# Modelos de mensagem

Após atualizar o CRM, execute `php artisan migrate --force` e `php artisan optimize:clear`.
Esta atualização não exige mudanças no servidor Baileys.

Em **Configurações → Módulos da empresa**, aparecem **Conexão do WhatsApp** e **Modelos de mensagem**.
O suporte controla a ativação dos módulos. A migração habilita os dois para empresas existentes, preservando o acesso anterior ao WhatsApp.
Os administradores da empresa gerenciam seus modelos; colaboradores precisam das permissões de leitura/escrita correspondentes.

Em **Modelos de mensagem**, crie, edite e exclua textos para e-mail ou WhatsApp:

- Comprovante: `{nome}`, `{empresa}`, `{venda}`, `{total}`, `{comprovante}`.
- Cobrança: `{nome}`, `{empresa}`, `{descricao}`, `{saldo}`, `{vencimento}`.
- Mensagem livre: `{nome}`, `{empresa}`.

Use um modelo padrão por canal/finalidade. O padrão de comprovante é aplicado aos novos envios gerados pela venda; o padrão de cobrança por e-mail é aplicado ao enviar lembretes. Mensagens já registradas na fila mantêm o texto original.
O e-mail de venda inclui a mensagem personalizada junto do comprovante detalhado.
Em lembretes financeiros, **Cobrar com modelo** abre o editor de envio com cliente, conta e modelo padrão de WhatsApp selecionados, para revisão e envio manual.

Também é possível enviar qualquer modelo diretamente pela página. Comprovantes exigem uma venda ativa do cliente e cobranças exigem uma conta a receber pendente do cliente. Mensagens livres exigem consentimento no cadastro para o canal escolhido.
E-mails usam a fila SMTP existente. WhatsApp exige conexão ativa e usa o envio do atendimento, que mantém os eventos no histórico sincronizado.
O histórico mostra os resultados dos envios manuais. Em “Sem confirmação”, confira no celular antes de iniciar uma nova solicitação. O sistema não repete automaticamente um envio de resultado incerto.

Desativar Modelos de mensagem bloqueia o editor e restaura o texto original para novos envios automáticos. Desativar Conexão do WhatsApp bloqueia conexão e atendimento e impede novos comprovantes por WhatsApp. Não apaga sessões ou histórico existentes.
