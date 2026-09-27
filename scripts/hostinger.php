<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Run through SSH only. Never expose deployment actions as web endpoints.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
$runtimeCandidates = ['/opt/alt/php83/usr/bin/php', '/opt/alt/php84/usr/bin/php', '/opt/alt/php82/usr/bin/php'];
// hPanel selects the website runtime, independently from the SSH `php` command.
if (PHP_VERSION_ID < 80200) {
    echo '[AVISO] O terminal usa PHP '.PHP_VERSION.'. A versão escolhida no hPanel pode ser diferente.'.PHP_EOL;
    foreach ($runtimeCandidates as $binary) {
        if (! is_file($binary) || ! is_executable($binary) || realpath($binary) === realpath(PHP_BINARY)) {
            continue;
        }
        $command = escapeshellarg($binary).' '.escapeshellarg(__FILE__).' '.implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));
        if (in_array('--runtime-restarted', $argv, true) || ! function_exists('proc_open')) {
            echo '[AÇÃO] Execute com o PHP compatível: '.$command.PHP_EOL;
            exit(1);
        }
        echo '[INFO] Reiniciando o instalador com '.$binary.PHP_EOL;
        $process = proc_open([$binary, __FILE__, ...array_slice($argv, 1), '--runtime-restarted'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $base);
        if (is_resource($process)) {
            exit(proc_close($process));
        }
        echo '[AÇÃO] Não foi possível reiniciar automaticamente. Execute: '.$command.PHP_EOL;
        exit(1);
    }
    echo '[AÇÃO] PHP 8.2+ não foi encontrado nos caminhos padrão. Peça à Hostinger o caminho do PHP CLI 8.3 e use-o no instalador, Artisan e cron.'.PHP_EOL;
    exit(1);
}
echo '[INFO] PHP '.PHP_VERSION.' | executável: '.PHP_BINARY.PHP_EOL;
echo '[INFO] Pasta da aplicação: '.$base.PHP_EOL;
if (in_array('--php-info', $argv, true)) {
    exit(0);
}
$install = in_array('--install', $argv, true);
$fixKey = in_array('--fix-key', $argv, true);
$initEnv = in_array('--init-env', $argv, true);
if (! is_file($base.'/bootstrap/app.php') || ! is_file($base.'/artisan')) {
    fwrite(STDERR, '[ERRO] Envie este arquivo para scripts/ dentro da aplicação, ao lado das pastas app e bootstrap.'.PHP_EOL);
    exit(1);
}
if (! is_file($base.'/.env')) {
    if (! $install && ! $initEnv) {
        echo '[AÇÃO] Arquivo ausente: '.$base.'/.env'.PHP_EOL;
        echo 'Se já havia uma instalação, restaure o .env original para preservar a APP_KEY e as credenciais.'.PHP_EOL;
        echo 'Para uma instalação nova, execute este script com --init-env e edite o arquivo criado. No gerenciador de arquivos, habilite a exibição de arquivos ocultos.'.PHP_EOL;
        exit(1);
    }
    $template = $base.'/deploy/hostinger.env.example';
    $contents = is_file($template) ? file_get_contents($template) : "APP_NAME=\"PoseiTech CRM\"\nAPP_ENV=production\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=https://seu-dominio.com\nAPP_LOCALE=pt_BR\nLOG_CHANNEL=daily\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=PREENCHA_BANCO\nDB_USERNAME=PREENCHA_USUARIO\nDB_PASSWORD=\"PREENCHA_SENHA\"\nSESSION_DRIVER=file\nSESSION_ENCRYPT=true\nSESSION_SECURE_COOKIE=true\nCACHE_STORE=file\nQUEUE_CONNECTION=database\nMAIL_MAILER=log\n";
    // Exclusive creation: never overwrite an existing environment, even concurrently.
    $file = @fopen($base.'/.env', 'x');
    if (! $file) {
        fwrite(STDERR, '[ERRO] Não foi possível criar '.$base.'/.env. Confira a permissão da pasta ou se o arquivo já existe.'.PHP_EOL);
        exit(1);
    }
    @chmod($base.'/.env', 0600);
    $written = fwrite($file, $contents);
    fclose($file);
    if ($written !== strlen($contents)) {
        fwrite(STDERR, '[ERRO] Escrita incompleta do .env. Confira o espaço em disco e edite o arquivo antes de continuar.'.PHP_EOL);
        exit(1);
    }
    echo '[AÇÃO] Modelo criado em '.$base.'/.env'.PHP_EOL;
    echo 'Preencha APP_URL e DB_DATABASE, DB_USERNAME, DB_PASSWORD (e DB_HOST conforme o hPanel). Depois execute --install novamente.'.PHP_EOL;
    echo 'Nenhuma chave foi gerada e nenhum banco foi alterado. Se já existiam dados, restaure a APP_KEY original.'.PHP_EOL;
    exit($initEnv ? 0 : 2);
}
if ($initEnv) {
    echo '[OK] O .env já existe e foi preservado: '.$base.'/.env'.PHP_EOL;
    exit(0);
}
$failed = false;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? '[OK] ' : '[ERRO] ').$message.PHP_EOL;
    $failed = $failed || ! $ok;
};

$check(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP 8.2 ou superior; versão atual: '.PHP_VERSION);
foreach (['ctype', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'PDO', 'session', 'tokenizer', 'xml'] as $extension) {
    $check(extension_loaded($extension), 'Extensão '.$extension);
}
$check(is_file($base.'/vendor/autoload.php'), 'Dependências vendor instaladas');
$check(is_readable($base.'/.env'), 'Arquivo de configuração legível: '.$base.'/.env');
foreach (['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $folder) {
    $path = $base.'/'.$folder;
    if (($install || $fixKey) && ! is_dir($path)) {
        mkdir($path, 0755, true);
    }
    $check(is_dir($path) && is_writable($path), $folder.' existe e permite escrita');
}
if ($failed) {
    echo 'Corrija os itens acima antes de continuar. Não use permissões 777.'.PHP_EOL;
    exit(1);
}

if ($install || $fixKey) {
    $environment = file_get_contents($base.'/.env');
    if (! preg_match('/^\s*APP_KEY\s*=/m', $environment)) {
        if (! is_writable($base.'/.env')) {
            fwrite(STDERR, '[ERRO] O arquivo .env não permite escrita.'.PHP_EOL);
            exit(1);
        }
        file_put_contents($base.'/.env', PHP_EOL.'APP_KEY='.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    // These are generated caches, never customer data, secrets, or uploaded files.
    foreach (['config.php', 'events.php', 'routes-v7.php', 'packages.php', 'services.php'] as $cache) {
        if (is_file($base.'/bootstrap/cache/'.$cache)) {
            unlink($base.'/bootstrap/cache/'.$cache);
        }
    }
    foreach (glob($base.'/storage/framework/views/*.php') ?: [] as $view) {
        if (! is_link($view)) {
            unlink($view);
        }
    }
}

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
try {
    $kernel->bootstrap();
    if ($install) {
        $databaseConfig = config('database.connections.'.config('database.default'), []);
        foreach (['database', 'username', 'password'] as $field) {
            $value = (string) ($databaseConfig[$field] ?? '');
            $check($value !== '' && ! preg_match('/PREENCHA|SUBSTITUA|u123456789/i', $value), 'Dados reais do banco configurados: '.$field);
        }
        $check(str_starts_with(config('app.url'), 'https://') && ! str_contains(config('app.url'), 'seu-dominio'), 'APP_URL contém o domínio real com HTTPS');
        if ($failed) {
            echo 'Edite o .env indicado acima e execute --install novamente. Nenhuma migration foi aplicada.'.PHP_EOL;
            exit(1);
        }
    }
    if (($install || $fixKey) && ! config('app.key')) {
        $code = $kernel->call('key:generate', ['--force' => true]);
        if ($code !== 0 || ! config('app.key')) {
            throw new RuntimeException('Não foi possível gerar APP_KEY.');
        }
        echo '[OK] APP_KEY inicial gerada. Chaves existentes nunca são substituídas.'.PHP_EOL;
    }
    $check((bool) config('app.key'), 'APP_KEY configurada');
    if ($fixKey) {
        if ($failed) {
            exit(1);
        }
        echo 'Chave verificada e caches antigos removidos. Dados e senhas do banco não foram alterados. Atualize a página do site.'.PHP_EOL;
        exit(0);
    }
    $check(config('app.env') === 'production', 'APP_ENV=production');
    $check(! config('app.debug'), 'APP_DEBUG=false');
    $check(str_starts_with(config('app.url'), 'https://') && ! str_contains(config('app.url'), 'seu-dominio'), 'APP_URL contém o domínio real com HTTPS');
    $connection = config('database.default');
    $check(in_array($connection, ['mysql', 'mariadb']), 'MySQL/MariaDB selecionado para hospedagem');
    $check(extension_loaded('pdo_mysql'), 'Extensão pdo_mysql');
    if ($failed) {
        exit(1);
    }
    try {
        DB::connection()->getPdo();
        $check(true, 'Conexão com o banco');
    } catch (Throwable $e) {
        $check(false, 'Conexão falhou. Confira DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME e DB_PASSWORD no hPanel/.env. Código: '.$e->getCode());
        exit(1);
    }
    if ($install) {
        foreach ([['migrate', ['--force' => true]], ['db:seed', ['--force' => true]], ['route:cache', []], ['view:cache', []]] as [$command, $arguments]) {
            $code = $kernel->call($command, $arguments);
            echo $kernel->output();
            if ($code !== 0) {
                throw new RuntimeException('Falha no comando '.$command);
            }
        }
    }
    foreach (['users', 'companies', 'plans', 'sales', 'accounts', 'platform_settings', 'customer_credits', 'sessions', 'cache'] as $table) {
        $check(Schema::hasTable($table), 'Tabela '.$table);
    }
    $pending = array_diff(array_keys($app->make('migrator')->getMigrationFiles([$base.'/database/migrations'])), $app->make('migration.repository')->getRan());
    $check(! $pending, 'Migrations atualizadas');
    echo $failed ? 'Execute php scripts/hostinger.php --install após corrigir a configuração.'.PHP_EOL : 'Instalação verificada. Abra o domínio e cadastre a primeira empresa.'.PHP_EOL;
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    // Detailed errors remain in the private server logs, not in a public page.
    fwrite(STDERR, '[ERRO] Instalação interrompida: '.get_class($e).'. Consulte storage/logs/laravel.log e o log PHP da hospedagem.'.PHP_EOL);
    exit(1);
}
