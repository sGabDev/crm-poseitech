# Teste local de empresas e clientes

## Validação no MariaDB real — 02/11/2026

Foi usado o servidor configurado no `.env`, MariaDB **11.8.9**, com quatro conexões simultâneas. A conta revelou **MAX_CONNECTIONS_PER_HOUR=500**; `max_user_connections` do servidor é 75. Não havia migrations pendentes e as tabelas persistentes verificadas usam InnoDB.

O comando `npm run test:load:mysql` copia apenas a estrutura das tabelas para tabelas **temporárias privadas de cada conexão**, cria dados sintéticos nessas cópias e exercita os serviços reais de negócio. Não executa migrations, não lê registros comerciais existentes para montar o teste e não grava vendas nas tabelas persistentes. Reconexão é proibida: perder a sessão interrompe o teste, para impedir acesso acidental às tabelas originais após perder as cópias temporárias. Encerrar a conexão elimina as cópias.

Resultado às 09:40, horário de São Paulo:

| Verificação | Resultado |
| --- | ---: |
| Empresas | 110 |
| Clientes | 1.100 |
| Vendas concluídas nas tabelas temporárias | 110 |
| Repetições com a mesma chave, sem duplicar venda/pagamento | 110 |
| Rollbacks após venda, com conferência de venda/pagamento/caixa/estoque | 110 |
| Consultas SQL durante o cenário, após criar as estruturas | 10.792 |
| Erros | 0 |
| Duração | 145,57 s |
| p95 do cenário de venda | 892 ms |

Relatório: `.runtime/load-mysql-report.json`. Esta rodada mede serviços e consultas no banco real com conexões reutilizadas. Não é carga HTTP de produção, nem mede contenção entre transações sobre a mesma empresa: cada processo possui tabelas temporárias próprias. `CREATE TABLE ... LIKE` preserva colunas e índices, mas não replica triggers ou chaves estrangeiras; a rodada não certifica esses mecanismos. Os quatro processos distribuem as 110 empresas, com dez clientes por empresa.

`php tests/load/mysql-recovery.php` também confirmou uma pausa **local simulada de 300 s** liberada em **162 ms**, com um `SELECT 1` no banco real e arquivo de circuito exclusivo do teste. Não foi provocada suspensão real no provedor.

### Impedimento para garantir vendas sem suspensão

O limite de **500 novas conexões por hora** é da conta do provedor, não do número de empresas ou da capacidade global de conexões do MariaDB. O backend atual usa PDO não persistente e libera a conexão ao terminar a requisição. Se cada requisição precisar de uma nova conexão, 500/h correspondem a apenas cerca de **8,3 requisições por minuto**, contando também cron e workers. Por isso a carga HTTP de 2.530 requisições não foi direcionada diretamente à conta real.

**Para sustentar o volume solicitado sem atingir essa quota, a hospedagem precisa aumentar/remover `MAX_CONNECTIONS_PER_HOUR` ou disponibilizar um pool real de conexões compatível e validado.** A configuração de ProxySQL descrita em `MYSQL.md` não significa que o proxy esteja ativo. Não foi instalado ou ativado um proxy nesta execução, nem alterado qualquer limite do provedor. Não habilite persistência PDO como substituição automática de um pool limitado e supervisionado.

As correções no aplicativo preservam a pausa breve em falhas de saturação originadas nos envios, impedem que falhas concorrentes prolonguem uma pausa ativa e permitem sondagem de recuperação com `SELECT 1`, no máximo uma por instância a cada `DB_CAPACITY_RECHECK_SECONDS` (30 s por padrão), acionada por novos acessos HTTP. Banco recuperado libera o acesso antes de acabar a pausa local. O `Retry-After` acompanha esse intervalo. Uma conexão/consulta disponível não comprova liberação de quota específica de escritas; uma escrita ainda recusada volta a receber o tratamento de indisponibilidade. Nenhuma venda é repetida automaticamente após erro ou confirmação perdida.

Para publicar essas correções: envie os arquivos alterados, execute `php artisan config:cache` no servidor e reinicie os workers de longa duração. A publicação na hospedagem não foi executada nesta validação.

Validação final das correções: `npm test` passou com **168 testes PHP e 1.586 assertions**, além dos testes JavaScript e da verificação de sintaxe. Pint passou nos arquivos PHP alterados nesta revisão. Os testes cobrem a pausa curta de saturação nos envios, recuperação antecipada, limitação e concorrência das sondagens, preservação de uma pausa mais recente e ausência de prolongamento da pausa por falhas repetidas.

## Teste HTTP local com SQLite

Execute `npm run test:load` na raiz do projeto. Requer Node com `fetch` e PHP 8.2+ com PDO SQLite; no Windows usa `C:/xampp/php/php.exe`. Para outro caminho, configure `PHP_BINARY`.

O teste cria 110 empresas, um administrador por empresa, 1.100 clientes com sessões individuais do portal e um produto por empresa. Faz 2.530 requisições HTTP reais: 330 para dashboard, listagem de clientes e vendas; 1.100 para o portal e 1.100 para os catálogos. Confere HTTP 200, conteúdo da identidade esperada e ausência de identificadores sintéticos de outras empresas/clientes nas respostas verificadas. Redirecionamentos e timeouts contam como falha.

São oito processos PHP locais, com 32 requisições em andamento por padrão. `LOAD_CONCURRENCY` permite ajustar a concorrência entre 1 e 1.210. Todas as sessões são preparadas antes da carga: o teste mede páginas após autenticação, sem incluir login, cadastro, checkout, pagamentos, envios externos ou arquivos estáticos do navegador.

O banco SQLite WAL, sessões, caches, logs e chave de criptografia ficam em um diretório temporário exclusivo. O teste não modifica o `.env` nem utiliza o banco configurado nele. Os processos são encerrados e os dados temporários removidos ao terminar. O relatório sem cookies ou credenciais fica em `.runtime/load-local-report.json`, com volume, duração, vazão, erros e percentis por grupo. O comando termina com código diferente de zero se houver falha funcional; as latências são medidas, sem limite de aprovação configurado.

Essa medição descreve o servidor PHP de desenvolvimento e SQLite locais. Para medir a capacidade de produção, repita os cenários em homologação com MySQL, servidor web, recursos e limites equivalentes aos da hospedagem. O cenário k6 em `tests/load/database.k6.js` continua disponível para medir o catálogo nessa infraestrutura, conforme `MYSQL.md`.

## Resultado em 02/11/2026

Rodada concluída às 09:21 no horário de São Paulo, com 110 empresas, 1.100 clientes e 32 requisições simultâneas em oito processos PHP. As 2.530 requisições passaram sem erros funcionais ou identificadores inesperados nas respostas verificadas. Duração: 138,31 segundos; vazão: 18,29 requisições/segundo.

| Grupo | Requisições | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: |
| Empresas | 330 | 1.726 ms | 2.118 ms | 2.249 ms |
| Portal de clientes | 1.100 | 1.723 ms | 2.071 ms | 2.215 ms |
| Catálogos | 1.100 | 1.703 ms | 1.987 ms | 2.186 ms |

As falhas iniciais estavam na preparação do teste: caminhos absolutos de cache no Windows, contas ainda exigindo troca inicial de senha e reaproveitamento de atributos entre sessões sintéticas. O gerador foi corrigido, e a rodada acima passou com identidades independentes. Não foram encontrados erros de aplicação que exigissem alteração do código de negócio nesse cenário.

Verificação complementar: `npm test` passou com 161 testes PHP e 1.564 assertions, além dos testes JavaScript e sintaxe. `npm run check` passou após os ajustes finais; os três novos arquivos PHP foram formatados com Pint.
