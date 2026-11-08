<?php

namespace App\Console\Commands;

use App\Database\AccountLimits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDOException;

class DatabaseDoctor extends Command
{
    protected $signature = 'poseitech:db-doctor';

    protected $description = 'Verifica conectividade e limites do banco sem alterar dados ou mostrar credenciais';

    public function handle(): int
    {
        $connection = DB::connection();
        $start = microtime(true);
        try {
            $connection->select('SELECT 1');
            $this->info('Conexao OK: '.round((microtime(true) - $start) * 1000, 1).' ms');
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $variables = $connection->select("SHOW VARIABLES WHERE Variable_name IN ('max_connections', 'max_user_connections', 'wait_timeout', 'innodb_lock_wait_timeout')");
                $status = $connection->select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running', 'Max_used_connections', 'Aborted_connects')");
                $this->table(['Metrica', 'Valor'], array_map(fn ($row) => [(array_values((array) $row))[0], (array_values((array) $row))[1]], array_merge($variables, $status)));
                try {
                    $limits = AccountLimits::parse($connection->select('SHOW GRANTS FOR CURRENT_USER'));
                    if ($limits) {
                        $this->table(['Limite da conta', 'Valor (0 = sem quota explicita)'], array_map(fn ($key, $value) => [$key, $value], array_keys($limits), array_values($limits)));
                    }
                } catch (PDOException) {
                    $this->warn('Nao foi possivel ler os limites explicitos da conta.');
                }
                $this->warn('Limites por conta/hora e capacidade do plano precisam ser confirmados com o provedor.');
            }
            $this->line('Sessao: '.config('session.driver').'; cache: '.config('cache.default').'; fila: '.config('queue.default'));
            $this->line('Este diagnostico nao certifica capacidade. Execute o teste de carga em homologacao.');

            return self::SUCCESS;
        } catch (PDOException $error) {
            $this->error('Falha no diagnostico. Codigo do driver: '.(int) ($error->errorInfo[1] ?? 0));

            return self::FAILURE;
        } finally {
            $connection->disconnect();
        }
    }
}
