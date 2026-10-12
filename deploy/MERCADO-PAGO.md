# Mercado Pago por empresa

## Instalação

1. Publique os arquivos do CRM e execute `php artisan migrate --force` e `php artisan optimize:clear`.
2. Configure `APP_URL` com o endereço HTTPS público do CRM. Preserve `APP_KEY`: ela protege as credenciais criptografadas de cada empresa.
3. Em **Configurações → Mercado Pago**, informe o Access Token da aplicação da empresa e a assinatura secreta de Webhooks. Escolha teste ou produção e salve para validar a conta recebedora.
4. Na aplicação Mercado Pago, configure a URL exibida nessa tela e habilite notificações de **Pagamentos** no ambiente correspondente. Permita acesso público ao endpoint; ele valida a assinatura HMAC antes de consultar o pagamento na API.
5. Mantenha o cron Laravel ativo: `* * * * * php /caminho/do/crm/artisan schedule:run`. E-mails usam a fila SMTP existente; a reconciliação Mercado Pago roda a cada cinco minutos. O webhook confirma pagamentos imediatamente quando recebido.
6. Para WhatsApp, conecte o bot automático da empresa, habilite o módulo e cadastre o telefone do cliente. Para e-mail, configure SMTP e o endereço do cliente.

## Uso

- **Venda:** selecione um cliente e a forma **Mercado Pago · link de pagamento**. Use essa forma sozinha; saldo da conta do cliente pode abater parte do total. A venda reserva estoque e fica aguardando pagamento. O CRM cria o link e tenta os dois canais; confira o painel de envios da venda.
- **Dívida:** na conta a receber, clique **Link Mercado Pago**. Na página do link, escolha e-mail ou WhatsApp, ou copie a URL.
- **Acompanhamento:** na venda, abra **Ver pagamento e envios**. A aprovação atualiza o financeiro e gera uma notificação no CRM. O botão **Consultar pagamento** consulta diretamente o provedor, inclusive quando um webhook estiver atrasado.
- E-mail **na fila** ainda não significa enviado: confira o resultado SMTP em Configurações. Envios repetidos do mesmo link/canal não duplicam mensagens. Em falha ambígua, confira a conversa/fila e compartilhe o link manualmente se necessário.
- O link expira em 24 horas. Após expirar, use novamente a conta/venda para gerar outro. Uma criação sem confirmação é reconciliada por referência antes de qualquer nova tentativa; não se repete cegamente um POST.

## Confirmação e estornos

O retorno do navegador não quita contas. O CRM consulta o pagamento autenticado na API e confere referência, conta recebedora, moeda, ambiente e valor. Webhooks repetidos não lançam pagamentos duplicados. Pagamentos com saldo alterado ou duplicados são sinalizados para conferência, sem baixa adicional.

Em **teste**, aprovação gera “Aprovado em teste · sem baixa”; nenhuma dívida real é quitada. Valide com credenciais e compradores de teste próprios do Mercado Pago antes de operar em produção. Não houve cobrança real durante o desenvolvimento.

Para cancelar uma venda paga por Mercado Pago, primeiro reembolse no painel Mercado Pago e aguarde a confirmação. Reembolso integral ou chargeback reabre o saldo uma única vez. Reembolso parcial exige conferência e conciliação manual; não é convertido automaticamente em estorno integral. A aplicação não solicita devoluções automaticamente.

Não troque conta recebedora ou ambiente quando houver links/pagamentos a acompanhar. O token pode ser renovado para a mesma conta. Desativar novos links não interrompe a recepção de confirmações de pagamentos anteriores. A reconciliação agendada cobre links dos últimos 180 dias; webhooks e consulta manual continuam disponíveis para registros antigos.

Documentação oficial: [preferências Checkout Pro](https://www.mercadopago.com.br/developers/en/reference/online-payments/checkout-pro-preferences/create-preference/post) e [notificações e assinatura](https://www.mercadopago.com.br/developers/en/docs/checkout-pro-preferences/payment-notifications).
