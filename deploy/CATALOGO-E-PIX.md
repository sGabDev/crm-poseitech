# Catálogo e Pix imediato

## Publicação e configuração

1. Publique os arquivos e execute `php artisan migrate --force`, `php artisan optimize:clear` e `php artisan view:cache`. A migração `2026_10_13_000010_catalog_checkout_pix` adiciona o vínculo entre pedido, cliente e pagamento, além do índice do CPF normalizado. Faça backup antes da migração.
2. O Super Admin precisa habilitar **Catálogo**, **Clientes** e **Pedidos** na empresa; habilite **Delivery** para oferecer entrega. O usuário da empresa precisa de acesso ao catálogo para configurar a vitrine.
3. Em **Configurações → Catálogo e automações**, configure capa, banner, informações, valor mínimo, taxa fixa de entrega e prazo. Marque **Receber pedidos pelo catálogo** e **Loja aberta para novos pedidos**. Horários são informativos; o controle de abertura é manual.
4. Para Pix, configure o Mercado Pago da empresa com Access Token, conta recebedora validada, modo correto e assinatura de Webhooks. Use HTTPS em `APP_URL` e configure o webhook de Pagamentos conforme [MERCADO-PAGO.md](MERCADO-PAGO.md). A conta Mercado Pago deve estar habilitada para receber Pix.
5. Mantenha o cron Laravel e o serviço de envios conforme [ENVIOS-E-ESCALA.md](ENVIOS-E-ESCALA.md). A reconciliação de pagamentos e liberação de reservas roda a cada cinco minutos.

## Cadastro e pedido

- O catálogo inclui vitrine, seis destaques iniciais, categorias, busca, adicionais, carrinho, informações da loja e perfil do visitante. Capa, banner e fotos são próprios de cada empresa.
- Antes de concluir o pedido, o visitante informa nome, CPF válido, e-mail, telefone, endereço, bairro, cidade e nascimento opcional.
- O CPF é pesquisado somente dentro da empresa. Se existir, **somente endereço, bairro e cidade** são atualizados; identidade, contatos, consentimentos e informações internas existentes são preservados. Se não existir, cria-se um cliente com origem **Catálogo online**, respeitando o limite do plano.
- O cadastro por CPF não autentica a identidade civil da pessoa. O site não retorna dados anteriores do CRM nem libera histórico por CPF. A sessão guarda apenas os dados preenchidos pelo próprio visitante e fica vinculada ao cookie do navegador.
- Escolha entrega ou retirada e Pix online, dinheiro ou cartão na entrega. A taxa exibida é fixa por empresa; não há cotação por distância/CEP nem rastreamento geográfico.
- Preços, adicionais, estoque, pedido mínimo e frete são recalculados no servidor. O histórico de pedidos permanece privado ao navegador que fez o pedido.

## Pix e financeiro

- O Pix é criado imediatamente pela API Payments do Mercado Pago, com QR code e código copia e cola. Cada pedido mantém uma referência, uma cobrança e um corpo imutável; retentativas usam a mesma `X-Idempotency-Key`, inclusive após falha de conexão.
- O estoque do pedido Pix fica reservado antes da criação externa. Chamadas externas ocorrem após o commit; uma falha de rede preserva o pedido e permite consultar novamente a geração na mesma tela.
- O vencimento solicitado é de 30 minutos. A liberação de estoque depende de o provedor confirmar cancelamento, recusa ou estorno; uma criação ambígua não é liberada sem conferência. A reconciliação tenta cancelar cobranças expiradas que ainda estejam pendentes. Reservas ambíguas precisam ser acompanhadas pelo suporte.
- A confirmação consulta o pagamento autenticado e verifica referência, conta recebedora, método Pix, moeda, ambiente e valor. O retorno do navegador não dá baixa. A página consulta apenas o estado local, evitando chamadas repetidas ao provedor por visitante.
- Em **Pedidos**, a equipe consulta o estado do Pix e registra a venda depois da aprovação real. A venda exige caixa aberto, itens e valor originais; reutiliza o recebimento e a conta existentes, sem cobrar novamente nem descontar o estoque outra vez.
- O pedido pago continua sujeito ao aceite operacional da empresa. Para cancelar um pedido pago, faça o reembolso no Mercado Pago e aguarde a confirmação. Depois cancele o pedido ou a venda vinculada. Reembolso parcial fica em conferência manual.
- Links de pagamento usam referência aleatória e mostram somente os dados necessários para pagar, sem CPF, telefone ou endereço. Trate esses links como privados e compartilhe apenas com o cliente. E-mail e WhatsApp são enfileirados quando os canais estão disponíveis; configure SMTP e bot automático para enviá-los.
- No modo de teste, aprovação não registra recebimento financeiro nem permite converter o pedido como pago. Não foram realizadas cobranças reais durante o desenvolvimento.

Documentação usada: [Pix via API Payments](https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix?scope=prod).
