# Unir clientes duplicados

Publique os arquivos do CRM, execute `php artisan migrate --force` e `php artisan optimize:clear`.

No perfil do cliente que deve permanecer, clique **Unir cliente duplicado**. Pesquise o outro cadastro, compare os dados e escolha os valores finais. Também é possível inverter o cadastro que permanece antes de confirmar. A ferramenta está disponível para administradores da empresa com acesso ao módulo Clientes.

A confirmação transfere vendas, contas, pagamentos, pontos, créditos, depósitos, saldo da conta, cupons, conversas, histórico de mensagens, envios de modelos e links Mercado Pago. Os valores financeiros e identificadores dos lançamentos permanecem iguais; a união muda o vínculo do cliente. Números de WhatsApp distintos conservam suas conversas separadas, ligadas ao mesmo cadastro.

Tags são combinadas sem repetir diferenças apenas de maiúsculas/minúsculas. Observações são reunidas e dados divergentes são preservados nas observações e na auditoria. O cadastro duplicado é removido somente após todas as transferências, em uma única transação. Não há desfazer automático; confira que os registros pertencem à mesma pessoa.

Links e sessões antigos do portal deixam de funcionar. Gere um novo link pelo perfil resultante. Consentimentos para campanhas só são mantidos quando ambos autorizam o mesmo contato válido; permissões conflitantes não se tornam novas autorizações.

Históricos de e-mail e cupons não são apagados. Envios de campanha ainda pendentes que se tornariam duplicados são cancelados. Cupons repetidos não geram utilizações extras: preserva-se no máximo um benefício ainda disponível, e nenhum extra quando já houve utilização. Uma mensagem em andamento impede temporariamente a união. Uma alteração nos cadastros ou saldos após a comparação exige nova revisão.

A migração substitui dois índices únicos por índices de consulta para conservar históricos múltiplos de uma campanha/cupom após a união. Os fluxos de criação continuam protegidos pelo bloqueio da empresa e pelas verificações existentes. O rollback da migração é recusado quando há históricos duplicados, para evitar perder registros.
