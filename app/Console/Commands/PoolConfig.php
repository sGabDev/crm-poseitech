<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PoolConfig extends Command
{
    protected $signature = 'poseitech:pool-config';

    protected $description = 'Gera configuração privada inicial do ProxySQL e verifica o orçamento de conexões';

    public function handle(): int
    {
        $pool = config('database_pool');
        $db = config('database.connections.'.config('database.default'));
        foreach (['size', 'clients', 'wait_ms', 'query_ms', 'instances', 'upstream_port'] as $key) {
            if ($pool[$key] < 1) {
                $this->error('Limite inválido: '.$key);

                return self::FAILURE;
            }
        }
        $total = $pool['size'] * $pool['instances'] + $pool['reserve'];
        $this->line('Orçamento necessário: '.$total.' = pool × instâncias de proxy + reserva (inclua conexões diretas na reserva).');
        if ($pool['reserve'] < 0 || $pool['budget'] < $total) {
            $this->error('Defina DB_CONNECTION_BUDGET conforme o limite confirmado pelo provedor e ajuste a concorrência.');

            return self::FAILURE;
        }
        if (! in_array($db['driver'] ?? '', ['mysql', 'mariadb'], true) || ! $pool['upstream_host'] || empty($db['username']) || empty($db['password']) || ! empty($db['url'])) {
            $this->error('Use MySQL/MariaDB, DB_UPSTREAM_HOST e credenciais dedicadas; remova DB_URL para gerar o proxy.');

            return self::FAILURE;
        }
        // libconfig quoted strings: reject control characters, escape syntax delimiters.
        $quote = static function (string $value): string {
            if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Valor de configuração contém caracteres de controle.');
            }

            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        };
        try {
            $host = $quote($pool['upstream_host']);
            $user = $quote($db['username']);
            $password = $quote($db['password']);
            $schema = $quote($db['database']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $adminPassword = bin2hex(random_bytes(24));
        $contents = <<<CFG
        datadir="/var/lib/proxysql"
        admin_variables={
            admin_credentials="crm_pool_admin:$adminPassword"
            mysql_ifaces="127.0.0.1:6032"
        }
        mysql_variables={
            interfaces="127.0.0.1:6033"
            threads=2
            max_connections={$pool['clients']}
            multiplexing=true
            monitor_enabled=false
            free_connections_pct=10
            connect_timeout_server=1000
            connect_timeout_server_max={$pool['wait_ms']}
            connect_retries_on_failure=0
            query_retries_on_failure=0
            default_query_timeout={$pool['query_ms']}
            autocommit_false_is_transaction=true
            autocommit_false_not_reusable=true
            query_digests=false
            eventslog_default_log=0
        }
        mysql_servers=(
            { address=$host; port={$pool['upstream_port']}; hostgroup=0; max_connections={$pool['size']}; }
        )
        mysql_users=(
            { username=$user; password=$password; default_hostgroup=0; default_schema=$schema; transaction_persistent=1; max_connections={$pool['clients']}; }
        )
        CFG;
        $directory = storage_path('app/private/database-pool');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error('Não foi possível criar o diretório privado.');

            return self::FAILURE;
        }
        $path = $directory.'/proxysql.cnf';
        $oldMask = umask(0077);
        $file = @fopen($path, 'x');
        umask($oldMask);
        if ($file === false) {
            $this->error('Arquivo existente ou sem permissão; preserve-o e revise a nova configuração separadamente.');

            return self::FAILURE;
        }
        try {
            if (fwrite($file, $contents."\n") !== strlen($contents."\n")) {
                throw new \RuntimeException('Falha ao gravar configuração privada.');
            }
        } finally {
            fclose($file);
        }
        $this->info('Configuração criada em storage/app/private/database-pool/proxysql.cnf. Proteja as permissões e siga deploy/MYSQL.md antes de ativar.');

        return self::SUCCESS;
    }
}
